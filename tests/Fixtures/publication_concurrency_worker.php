<?php

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Project;
use App\Services\V2\PublicationService;
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
    unset($connection['name']); // The copied name would send hydrated models outside this transaction.
    $connection['url'] = null; // Already resolved by the parent connection.
    config(['app.env' => 'testing', 'database.default' => 'publication_concurrency', 'database.connections.publication_concurrency' => $connection]);
    DB::purge('publication_concurrency');
    // Prove absence protection does not depend on MySQL gap locks.
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $job = $input['job'];
    $project = Project::findOrFail($job['project_id']);
    $item = ContentItem::findOrFail($job['item_id']);
    $branch = ContentBranch::findOrFail($job['branch_id']);
    $workerConnection = DB::connection();
    foreach ([$project, $item, $branch] as $model) {
        if ($model->getConnection() !== $workerConnection) {
            throw new RuntimeException('Concurrency model connection drifted.');
        }
    }
    $parentLocked = false;
    DB::listen(function (QueryExecuted $query) use (&$parentLocked, $workerConnection): void {
        if ($query->connection !== $workerConnection) {
            throw new RuntimeException('Concurrency query connection drifted.');
        }
        $sql = strtolower($query->sql);
        if (str_starts_with(ltrim($sql), 'select') && str_contains($sql, 'content_branches') && str_contains($sql, 'for update')) {
            $parentLocked = true;
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
        $result = app(PublicationService::class)->publish($project, $item->id, $branch->id, $job['target'], []);
        $output = [
            'status' => 200,
            'created' => $result['created'],
            'publication_id' => $result['publication']['id'],
        ];
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        $output = ['status' => $error->getStatusCode(), 'publication_id' => null];
    }
    echo json_encode($output + ['parent_locked' => $parentLocked], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never print exceptions containing database credentials or DSNs.
    fwrite(STDERR, "Concurrency worker failed.\n");
    exit(1);
}
