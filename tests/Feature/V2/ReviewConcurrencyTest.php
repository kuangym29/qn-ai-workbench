<?php

namespace Tests\Feature\V2;

use App\Models\ContentBranch;
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
