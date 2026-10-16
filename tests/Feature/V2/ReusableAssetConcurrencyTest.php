<?php

namespace Tests\Feature\V2;

use App\Models\Project;
use App\Models\ReusableAsset;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * 复用资产「并发同名创建」Release Gate（镜像 PublicationConcurrencyTest）。
 *
 * 验证真实 MySQL 8.4 + InnoDB 下，两个并发 POST 同一 (project_id, type, name)：
 *   - 最终数据库只 1 行；
 *   - 一个 201（创建成功），一个 422（同名已存在）；
 *   - 绝不 500（唯一约束作为安全网被正确分类为冲突）。
 *
 * 只在 MySQL 上运行（SQLite 跳过）。本地用 phpunit.mysql84.local.xml（3306）验证，
 * CI 用 phpunit.mysql84.xml（Docker 3399）。端口 3399 仅在 GITHUB_ACTIONS 下允许本地复用。
 */
class ReusableAssetConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateReusableAssetDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent duplicate-create regression requires MySQL.');
        }

        $this->assertTrue(app()->environment('testing'), 'Requires the isolated testing environment.');
        $this->assertTrue(str_ends_with((string) DB::connection()->getDatabaseName(), '_test'), 'Requires a dedicated test database.');
        $this->assertTrue((string) DB::connection()->getConfig('port') !== '3399' || getenv('GITHUB_ACTIONS') === 'true', 'Existing local database is forbidden.');
        $this->migrateReusableAssetDatabase();
    }

    public function test_concurrent_duplicate_create_keeps_exactly_one_row(): void
    {
        $project = Project::factory()->create();

        $results = $this->workers([
            ['project_id' => $project->id, 'type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
            ['project_id' => $project->id, 'type' => 'character', 'name' => '妈妈', 'status' => 'missing'],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([201, 422], $statuses, '并发同名创建必须一个成功、一个明确冲突（不得 500）');
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $project->id)->count(), '数据库最终只能 1 行');
        $this->assertDatabaseCount('reusable_assets', 1);

        $created = array_values(array_filter($results, static fn (array $r): bool => $r['status'] === 201));
        $this->assertNotNull($created[0]['asset_id']);
        $this->assertSame(1, ReusableAsset::query()->whereKey($created[0]['asset_id'])->count());
    }

    public function test_concurrent_create_of_distinct_names_keeps_both(): void
    {
        $project = Project::factory()->create();

        $results = $this->workers([
            ['project_id' => $project->id, 'type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
            ['project_id' => $project->id, 'type' => 'scene', 'name' => '厨房', 'status' => 'missing'],
        ]);

        $this->assertSame([201, 201], array_column($results, 'status'));
        $ids = array_column($results, 'asset_id');
        $this->assertNotSame($ids[0], $ids[1]);
        $this->assertSame(2, ReusableAsset::query()->where('project_id', $project->id)->count());
    }

    private function workers(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reusable-asset-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/reusable_asset_concurrency_worker.php')], base_path());
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
