<?php

namespace Tests\Feature\V2;

use App\Enums\ReviewDecision;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\PublicationRecord;
use App\Services\V2\PublicationService;
use App\Services\V2\ReviewDecisionService;
use App\Services\V2\ReviewSubmissionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Publication 并发 Release Gate（镜像 ReviewConcurrencyTest）。
 *
 * 验证 PublicationService::publish 在真实 MySQL 8.4 + InnoDB 行锁下：
 *   - 同一 exact version 的两个并发 POST → 数据库最终只 1 条记录；
 *   - 两个并发请求都拿到 200，且 publication_id 相同（一个创建、一个幂等）；
 *   - 发布事务对 content_branches 执行了 locking read（for update）。
 *
 * 该测试只在 MySQL 上运行（SQLite 跳过，与 ReviewConcurrencyTest 一致）。
 * 本地用 phpunit.mysql84.local.xml（端口 3306）验证；CI 用 phpunit.mysql84.xml
 * （Docker 端口 3399）验证。端口 3399 仅在 GITHUB_ACTIONS 下允许本地复用。
 */
class PublicationConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migratePublicationDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent row-lock regression requires MySQL.');
        }

        $this->assertTrue(app()->environment('testing'), 'Requires the isolated testing environment.');
        $this->assertTrue(str_ends_with((string) DB::connection()->getDatabaseName(), '_test'), 'Requires a dedicated test database.');
        $this->assertTrue((string) DB::connection()->getConfig('port') !== '3399' || getenv('GITHUB_ACTIONS') === 'true', 'Existing local database is forbidden.');
        $this->migratePublicationDatabase();
    }

    public function test_concurrent_publication_creates_exactly_one_record(): void
    {
        $scope = $this->approvedScope('article_copy');

        $results = $this->workers([
            ['operation' => 'publish', 'project_id' => $scope['project_id'], 'item_id' => $scope['item_id'], 'branch_id' => $scope['branch_id'], 'target' => 'article_copy'],
            ['operation' => 'publish', 'project_id' => $scope['project_id'], 'item_id' => $scope['item_id'], 'branch_id' => $scope['branch_id'], 'target' => 'article_copy'],
        ]);

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['publication_id'], $results[1]['publication_id']);
        $this->assertSame(1, PublicationRecord::count());
        $this->assertDatabaseCount('publication_records', 1);
        foreach ($results as $result) {
            $this->assertTrue($result['parent_locked'], 'Publication must execute a locking read of content_branches.');
        }
    }

    public function test_concurrent_publication_of_distinct_versions_keeps_both(): void
    {
        $scopeA = $this->approvedScope('article_copy');
        $scopeB = $this->approvedScope('article_copy');

        $results = $this->workers([
            ['operation' => 'publish', 'project_id' => $scopeA['project_id'], 'item_id' => $scopeA['item_id'], 'branch_id' => $scopeA['branch_id'], 'target' => 'article_copy'],
            ['operation' => 'publish', 'project_id' => $scopeB['project_id'], 'item_id' => $scopeB['item_id'], 'branch_id' => $scopeB['branch_id'], 'target' => 'article_copy'],
        ]);

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertNotSame($results[0]['publication_id'], $results[1]['publication_id']);
        $this->assertSame(2, PublicationRecord::count());
        foreach ($results as $result) {
            $this->assertTrue($result['parent_locked']);
        }
    }

    private function approvedScope(string $target): array
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'article']);
        ArticleContent::create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'content_branch_id' => $branch->id,
            'body' => '正式保存内容',
        ]);
        $review = app(ReviewSubmissionService::class)->submit($item->project, $item->id, $branch->id, $target);
        (new ReviewDecisionService)->decide($review, ReviewDecision::Approved, '审核通过', null);

        return [
            'project_id' => $item->project_id,
            'item_id' => $item->id,
            'branch_id' => $branch->id,
        ];
    }

    private function workers(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'publication-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/publication_concurrency_worker.php')], base_path());
                $process->setTimeout(25);
                // Send resolved config over stdin; credentials never appear in arguments or failure output.
                $process->setInput(json_encode([
                    'connection' => DB::connection()->getConfig(),
                    'directory' => $directory,
                    'worker' => $index,
                    'job' => $job,
                ], JSON_THROW_ON_ERROR));
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), 'Concurrency worker exited before the barrier.');
                }
                $this->assertLessThan($deadline, microtime(true), 'Concurrency workers did not reach the barrier.');
                usleep(10000);
            }
            touch($directory.'/go');

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Concurrency worker failed; output suppressed to protect connection data.');
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
