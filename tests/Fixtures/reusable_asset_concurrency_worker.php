<?php

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Exceptions\ReusableAssetNameConflictException;
use App\Models\Project;
use App\Services\V2\ReusableAssetIndexService;
use Illuminate\Contracts\Console\Kernel;
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
    unset($connection['name']);
    $connection['url'] = null;
    config(['app.env' => 'testing', 'database.default' => 'reusable_asset_concurrency', 'database.connections.reusable_asset_concurrency' => $connection]);
    DB::purge('reusable_asset_concurrency');
    // Prove duplicate protection does not depend on MySQL gap locks.
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

    $job = $input['job'];
    $project = Project::findOrFail($job['project_id']);
    if ($project->getConnection() !== DB::connection()) {
        throw new RuntimeException('Concurrency model connection drifted.');
    }

    touch($input['directory'].'/ready-'.$input['worker']);
    $deadline = microtime(true) + 15;
    while (! is_file($input['directory'].'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
    }

    try {
        $asset = app(ReusableAssetIndexService::class)->create(
            $project,
            ReusableAssetType::from($job['type']),
            $job['name'],
            ReusableAssetStatus::from($job['status']),
        );
        $output = ['status' => 201, 'asset_id' => $asset->id];
    } catch (ReusableAssetNameConflictException) {
        $output = ['status' => 422, 'asset_id' => null];
    }

    echo json_encode($output, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    // Never print exceptions containing database credentials or DSNs.
    fwrite(STDERR, "Concurrency worker failed.\n");
    exit(1);
}
