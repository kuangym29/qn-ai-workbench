<?php

use App\Enums\ReviewDecision;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Services\V2\ReviewDecisionService;
use App\Services\V2\ReviewRequestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $connection = $input['connection'];
    if (($connection['driver'] ?? null) !== 'mysql' || ((string) ($connection['port'] ?? '') === '3399' && getenv('GITHUB_ACTIONS') !== 'true')) {
        throw new RuntimeException('Unsafe test connection.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection['url'] = null; // Already resolved by the parent connection.
    config(['app.env' => 'testing', 'database.default' => 'review_concurrency', 'database.connections.review_concurrency' => $connection]);
    DB::purge('review_concurrency');
    // Prove absence protection does not depend on MySQL gap locks.
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $job = $input['job'];
    $review = $job['operation'] === 'decide' ? ReviewRequest::findOrFail($job['review_id']) : null;
    $project = $job['operation'] === 'create' ? Project::findOrFail($job['project_id']) : null;
    $branch = $job['operation'] === 'create' ? ContentBranch::findOrFail($job['branch_id']) : null;
    $parentLocked = false;
    DB::listen(function (QueryExecuted $query) use ($job, &$parentLocked): void {
        $sql = strtolower($query->sql);
        $parent = $job['operation'] === 'create' ? 'content_branches' : 'review_requests';
        if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, $parent) && str_contains($sql, 'for update')) {
            $parentLocked = true;
        }
        // Widen the original unsafe read/write window without synchronizing behind a held lock.
        if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'review_requests')) {
            usleep(300000);
        }
    });
    touch($input['directory'].'/ready-'.$input['worker']);
    $deadline = microtime(true) + 15;
    while (! is_file($input['directory'].'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
    }
    try {
        if ($job['operation'] === 'create') {
            $result = (new ReviewRequestService)->create($project, $branch, [
                'review_target_type' => 'image_post_copy', 'version_label' => 'concurrent-v1',
            ]);
        } else {
            $result = (new ReviewDecisionService)->decide($review, ReviewDecision::from($job['decision']), 'Concurrent review comment', null);
        }
        $output = ['status' => 200, 'id' => $result->id];
    } catch (DomainException) {
        // The HTTP controller maps this service conflict to 409.
        $output = ['status' => 409];
    }
    echo json_encode($output + ['parent_locked' => $parentLocked], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never print exceptions containing database credentials or DSNs.
    fwrite(STDERR, "Concurrency worker failed.\n");
    exit(1);
}
