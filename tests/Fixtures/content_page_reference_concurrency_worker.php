<?php

declare(strict_types=1);

/**
 * P5-I03-R1：ContentPage 显式资产引用的并发替换 worker。
 *
 * 与 tests/Gate/Support/concurrency_worker.php 同一族，但服务对象换成
 * ContentPageAssetReferenceService::replace()：
 *
 *   role A（orchestrated）：进入事务、拿到 content_pages 行锁、删完旧引用后，
 *                          在第一条引用 INSERT 之前暂停（事务未提交）；
 *   role B（orchestrated）：必须看到 A 已暂停才出手，随后撞上同一把行锁；
 *   race：两个 worker 同时等 go 信号，齐步调用 replace()。
 *
 * 为什么必须是独立进程：同一进程内的两次调用共享连接与 MySQL 会话，
 * CONNECTION_ID() 相同，既不会形成锁等待，也无法在 performance_schema 里
 * 区分「谁在等谁」。这里每个 worker 都是 PHP_BINARY 拉起的独立进程，
 * 有各自的 Laravel bootstrap 与 PDO 连接。
 *
 * 用法（stdin 传 JSON，凭据绝不进命令行）：
 *   php tests/Fixtures/content_page_reference_concurrency_worker.php < payload.json
 *
 * 退出码：
 *   0 = replace 干净完成
 *   2 = replace 抛出异常（含受控 InvalidArgumentException）
 *   3 = 用法 / payload 错误
 *   4 = 目标库自证失败（拒绝在未经证明的库上继续执行）
 *   5 = 编排失败（没等到必要的暂停 / go 信号，拒绝退化成顺序执行）
 */

use App\Models\ContentPage;
use App\Models\ContentPageReusableAssetReference;
use App\Services\V2\ContentPageAssetReferenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Gate\Support\ConcurrencyRuntimeDirectory;
use Tests\Gate\Support\PauseWatchdogTimeout;
use Tests\Gate\Support\WorkerPauseGate;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "[worker] 只能以 CLI 方式运行。\n");
    exit(3);
}

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

try {
    /** @var array<string, mixed> $input */
    $input = json_decode((string) stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "[worker] payload 不是合法 JSON：{$e->getMessage()}\n");
    exit(3);
}

$connection = (array) ($input['connection'] ?? []);

// 与 tests/Fixtures/reusable_asset_concurrency_worker.php 同一条安全边界：
// 只接受 mysql；3399 是 CI disposable 端口，本地直接复用会被拒绝。
if (($connection['driver'] ?? null) !== 'mysql'
    || ((string) ($connection['port'] ?? '') === '3399' && getenv('GITHUB_ACTIONS') !== 'true')) {
    fwrite(STDERR, "[worker] 拒绝在不安全的测试连接上运行。\n");
    exit(4);
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

unset($connection['name']);
$connection['url'] = null;
config([
    'app.env' => 'testing',
    'database.default' => 'content_page_reference_concurrency',
    'database.connections.content_page_reference_concurrency' => $connection,
]);
DB::purge('content_page_reference_concurrency');

$role = (string) ($input['role'] ?? '');
$mode = (string) ($input['mode'] ?? 'orchestrated');
$runtime = new ConcurrencyRuntimeDirectory((string) ($input['runtime_dir'] ?? ''));

$fail = static function (string $message, int $code) use ($role, $runtime): never {
    $runtime->putJson("result-{$role}.json", [
        'role' => $role,
        'status' => 'aborted',
        'message' => $message,
        'aborted_at' => microtime(true),
    ]);
    fwrite(STDERR, "[worker {$role}] {$message}\n");
    exit($code);
};

if ($role === '' || ! isset($input['page_id'], $input['character_asset_ids'], $input['runtime_dir'])) {
    $fail('payload 缺少 role / page_id / character_asset_ids / runtime_dir。', 3);
}

// ── 目标库自证 ───────────────────────────────────────────────────────
// worker 的 bootstrap 路径与测试进程不同，必须独立确认一次，否则一旦编排层
// 传错连接，Gate 会在错误的目标上跑出"结果"。
$actual = (array) config('database.connections.content_page_reference_concurrency');
$expected = (array) ($input['expected'] ?? []);

if ((string) config('database.default') !== 'content_page_reference_concurrency') {
    $fail('目标自证失败：database.default 不是本 worker 的专用连接。', 4);
}

foreach (['host' => 'expected_host', 'port' => 'expected_port', 'database' => 'expected_database'] as $key => $expectKey) {
    $want = (string) ($expected[$expectKey] ?? '');

    if ($want === '') {
        $fail("目标自证失败：payload 缺少 {$expectKey}。", 4);
    }

    if ((string) ($actual[$key] ?? '') !== $want) {
        $fail("目标自证失败：{$key} = '{$actual[$key]}'，期望 '{$want}'。", 4);
    }
}

// ── 会话身份 ───────────────────────────────────────────────────────
// MySQL 侧的 CONNECTION_ID() 是 performance_schema 里定位「谁在等谁」的
// 唯一可靠依据，必须在调用 service 之前取到。
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;

$runtime->putJson("conn-{$role}.json", [
    'role' => $role,
    'pid' => getmypid(),
    'conn_id' => $connectionId,
    'registered_at' => microtime(true),
]);

$lockReturnedAt = null;

DB::listen(static function (QueryExecuted $query) use (&$lockReturnedAt): void {
    if ($lockReturnedAt === null && str_contains(strtolower($query->sql), 'for update')) {
        // QueryExecuted 在语句**返回后**才触发，因此这是"拿到行锁"的时刻。
        $lockReturnedAt = microtime(true);
    }
});

if ($mode === 'race') {
    // 同时起跑：等父进程确认两个 worker 都已连接后再放行。
    $deadline = microtime(true) + 20.0;
    while (! $runtime->exists('go')) {
        if (microtime(true) > $deadline) {
            $fail('未在 20s 内收到 go 信号，拒绝继续。', 5);
        }
        usleep(10000);
    }
} elseif ($role === 'A') {
    // ── worker A：持锁暂停 ────────────────────────────────────────
    // 此刻 A 已进入事务、已拿到 content_pages 行锁、已删掉旧引用，
    // 但第一条引用 INSERT 尚未发生、事务也未提交。
    // 这是唯一能让 B 撞上同一把行锁并验证「先删后插是否被串行化」的窗口。
    Event::listen(
        'eloquent.creating: '.ContentPageReusableAssetReference::class,
        static function () use ($runtime, $connectionId, $input): void {
            $runtime->putJson('paused.json', [
                'role' => 'A',
                'phase' => 'reference_insert_paused',
                'conn_id' => $connectionId,
                'paused_at' => microtime(true),
            ]);

            // watchdog 只是"防父进程异常导致永久挂住"，不是"到点就释放"。
            // 唯一能放行 A 的是父进程的 release 信号；超时必须抛异常，
            // 让它冒泡出 replace() 的 DB::transaction 闭包 → 事务整体回滚 →
            // worker 非 0 退出。于是"超时"永远不可能被当成一次正常释放。
            $pauseTimeout = (float) ($input['pause_timeout_seconds'] ?? 30.0);

            try {
                WorkerPauseGate::await(
                    static fn (): bool => $runtime->exists(ConcurrencyRuntimeDirectory::RELEASE_SIGNAL),
                    $pauseTimeout,
                );
            } catch (PauseWatchdogTimeout $timeout) {
                $runtime->putJson('a-watchdog.json', [
                    'role' => 'A',
                    'conn_id' => $connectionId,
                    'pause_timeout_seconds' => $pauseTimeout,
                    'timed_out_at' => microtime(true),
                ]);

                throw $timeout;
            }

            $runtime->putJson('released.json', [
                'role' => 'A',
                'conn_id' => $connectionId,
                'resumed_at' => microtime(true),
            ]);
        },
    );
} else {
    // ── worker B：必须看到 A 已暂停 ────────────────────────────────
    // 看不到 A 的暂停信号就绝不出手，否则"并发"会退化成顺序执行，
    // 而顺序执行证明不了任何互斥语义。
    $paused = $runtime->awaitJson('paused.json', 20.0);

    if ($paused === null) {
        $fail('worker B 未能在 20s 内等到 worker A 的持锁暂停信号，拒绝以顺序方式执行。', 5);
    }

    $runtime->putJson('b-armed.json', [
        'role' => 'B',
        'conn_id' => $connectionId,
        'armed_at' => microtime(true),
        'a_paused_at' => $paused['paused_at'] ?? null,
    ]);
}

// ── 真实 service 调用 ───────────────────────────────────────────────
$page = ContentPage::query()->findOrFail((int) $input['page_id']);

if ($page->getConnection() !== DB::connection()) {
    $fail('并发模型连接漂移：模型连接与 worker 连接不是同一个。', 4);
}

$callStartedAt = microtime(true);

try {
    $characterAssetIds = array_map(
        static fn ($id): int => (int) $id,
        (array) $input['character_asset_ids'],
    );
    $sceneAssetId = array_key_exists('scene_asset_id', $input) && $input['scene_asset_id'] !== null
        ? (int) $input['scene_asset_id']
        : null;

    $result = app(ContentPageAssetReferenceService::class)->replace($page, $characterAssetIds, $sceneAssetId);

    $output = ['role' => $role, 'status' => 'ok', 'result' => $result];
    $exitCode = 0;
} catch (Throwable $e) {
    // 受控的 InvalidArgumentException（跨项目 / 类型不符 / 未知 id）也要如实报回：
    // 它意味着校验路径被触发，编排方必须能区分"干净完成"与"受控拒绝"。
    $output = [
        'role' => $role,
        'status' => 'error',
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ];
    $exitCode = 2;
}

$output += [
    'conn_id' => $connectionId,
    'pid' => getmypid(),
    'call_started_at' => $callStartedAt,
    'lock_returned_at' => $lockReturnedAt,
    'returned_at' => microtime(true),
];

$runtime->putJson("result-{$role}.json", $output);

echo '__RESULT__'.json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

exit($exitCode);
