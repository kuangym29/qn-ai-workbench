<?php

namespace Tests\Feature\V2;

use App\Models\ContentBranch;
use App\Models\ArticleContent;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\ReviewRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateReviewDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent row-lock regression requires MySQL.');
        }

        // Check before DatabaseMigrations can rebuild anything.
        $this->assertTrue(app()->environment('testing'), 'Requires the isolated testing environment.');
        $this->assertTrue(str_ends_with((string) DB::connection()->getDatabaseName(), '_test'), 'Requires a dedicated test database.');
        $this->assertTrue((string) DB::connection()->getConfig('port') !== '3399' || getenv('GITHUB_ACTIONS') === 'true', 'Existing local database is forbidden.');
        $this->migrateReviewDatabase();
    }

    public function test_concurrent_creation_returns_one_pending_identity(): void
    {
        $project = Project::factory()->create();
        $item = ContentItem::factory()->for($project)->create();
        $branch = ContentBranch::factory()->for($item)->create();

        $results = $this->workers([
            ['operation' => 'create', 'project_id' => $project->id, 'branch_id' => $branch->id],
            ['operation' => 'create', 'project_id' => $project->id, 'branch_id' => $branch->id],
        ]);

        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertDatabaseCount('review_requests', 1);
        $this->assertDatabaseHas('review_requests', ['id' => $results[0]['id'], 'status' => 'pending']);
        foreach ($results as $result) {
            $this->assertTrue($result['parent_locked'], 'Creation must execute a locking read of content_branches.');
        }
    }

    public function test_concurrent_decisions_allow_one_success_and_one_conflict(): void
    {
        $review = ReviewRequest::factory()->create();
        $results = $this->workers([
            ['operation' => 'decide', 'review_id' => $review->id, 'decision' => 'approved'],
            ['operation' => 'decide', 'review_id' => $review->id, 'decision' => 'changes_requested'],
        ]);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('review_decisions', 1);
        $record = $review->decisions()->sole();
        $this->assertSame(1, $record->decision_no);
        $this->assertSame($record->decision->value, $review->fresh()->status->value);
        foreach ($results as $result) {
            $this->assertTrue($result['parent_locked'], 'Decision must execute a locking read of review_requests.');
        }
    }

    public function test_concurrent_resubmissions_cannot_reopen_a_decided_version(): void
    {
        foreach (['approved', 'changes_requested'] as $decision) {
            [$review, $job] = $this->submissionReview();
            (new \App\Services\V2\ReviewDecisionService)->decide($review, \App\Enums\ReviewDecision::from($decision), '修改后提交', null);
            $results = $this->workers([$job, $job]);
            $this->assertSame([422, 422], array_column($results, 'status'));
            $this->assertSame(1, ReviewRequest::where('content_branch_id', $review->content_branch_id)->count());
            $this->assertSame($decision, $review->fresh()->status->value);
            foreach ($results as $result) {
                $this->assertTrue($result['parent_locked']);
            }
        }
    }

    public function test_decision_racing_submission_never_creates_another_same_version(): void
    {
        foreach (['approved', 'changes_requested'] as $decision) {
            [$review, $job] = $this->submissionReview();
            $results = $this->workers([
                ['operation' => 'decide', 'review_id' => $review->id, 'decision' => $decision],
                $job,
            ]);
            $this->assertSame(200, $results[0]['status']);
            $this->assertContains($results[1]['status'], [200, 422]);
            if ($results[1]['status'] === 200) {
                $this->assertSame($review->id, $results[1]['id']);
            }
            $this->assertSame(1, ReviewRequest::where('content_branch_id', $review->content_branch_id)->count());
            $this->assertSame($decision, $review->fresh()->status->value);
            foreach ($results as $result) {
                $this->assertTrue($result['parent_locked']);
            }
        }
    }

    private function submissionReview(): array
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'article']);
        ArticleContent::create(['project_id' => $item->project_id, 'content_item_id' => $item->id, 'content_branch_id' => $branch->id, 'body' => '正式保存内容']);
        $review = app(\App\Services\V2\ReviewSubmissionService::class)->submit($item->project, $item->id, $branch->id, 'article_copy');

        return [$review, ['operation' => 'submit', 'project_id' => $item->project_id, 'branch_id' => $branch->id]];
    }

    private function workers(array $jobs): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'review-concurrency-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $processes = [];
        try {
            foreach ($jobs as $index => $job) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/review_concurrency_worker.php')], base_path());
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
