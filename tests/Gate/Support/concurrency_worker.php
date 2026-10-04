<?php

/**
 * Release Gate 专用 worker：在**独立进程**里调用真实的 DuplicateReviewService。
 *
 * 这个脚本不参与日常测试，只在 tests/Gate/Mysql84ConcurrencyTest.php 里由
 * ConcurrencyWorkerProcess 用 PHP_BINARY 拉起。它刻意走完整 CLI 路径：
 *
 *   PHP 进程隔离 → Laravel bootstrap → 自己的 PDO 连接 → 真实 service
 *
 * 因此 MySQL 侧会看到一个独立的 CONNECTION_ID()，两个 worker 才是两个真的会话，
 * 「同一行的排他锁」才有意义。任何把它改写成单进程内的两次调用都会让 Gate 失效。
 *
 * 用法：php tests/Gate/Support/concurrency_worker.php <payload.json>
 *
 * 退出码：
 *   0 = 成功拿到 Decision
 *   2 = service 抛出异常（含受控 ValidationException，也是要如实报告的结果）
 *   3 = 用法/参数错误
 *   4 = 目标库自证失败（拒绝在未经证明的库上继续执行）
 *   5 = worker B 没等到 worker A 进入 creating
 */

declare(strict_types=1);

use App\Models\ContentItem;
use App\Models\DuplicateReviewDecision;
use App\Services\DuplicateReviewService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Gate\Support\ConcurrencyRuntimeDirectory;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "[worker] 只能以 CLI 方式运行。\n");
    exit(3);
}

$payloadPath = $argv[1] ?? '';

if (! is_string($payloadPath) || $payloadPath === '' || ! is_file($payloadPath)) {
    fwrite(STDERR, "[worker] 缺少 payload 文件路径参数。\n");
    exit(3);
}

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

try {
    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) file_get_contents($payloadPath), true, 64, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, "[worker] payload 不是合法 JSON：{$e->getMessage()}\n");
    exit(3);
}

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$role = (string) ($payload['role'] ?? '');
$runtime = new ConcurrencyRuntimeDirectory((string) ($payload['runtime_dir'] ?? ''));

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

if ($role === '' || ! isset($payload['content_item_id'], $payload['data'])) {
    $fail('payload 缺少 role / content_item_id / data。', 3);
}

// ── 目标库自证 ─────────────────────────────────────────────────────
// Gate 的底线：worker 自己也要确认连的是约定的隔离测试库。
// 这条与 Gate bootstrap、shell 脚本属于同一安全边界，不是重复劳动——
// worker 的 bootstrap 路径与测试进程不同（走 .env.mysql-testing + 显式环境变量），
// 必须独立确认一次，否则一旦编排层传错 env，Gate 会在错误的目标上跑出"结果"。
$connection = (array) config('database.connections.mysql');
$expected = (array) ($payload['expected'] ?? []);
$actualDriver = (string) config('database.default');
$actualUrl = $connection['url'] ?? null;

if ($actualDriver !== 'mysql') {
    $fail("目标自证失败：database.default = '{$actualDriver}'，期望 'mysql'。", 4);
}

if ($actualUrl !== null && $actualUrl !== '') {
    $fail('目标自证失败：DB_URL 非空，逐项校验已失效。', 4);
}

foreach (['host' => 'expected_host', 'port' => 'expected_port', 'database' => 'expected_database'] as $key => $expectKey) {
    $actual = (string) ($connection[$key] ?? '');
    $want = (string) ($expected[$expectKey] ?? '');

    if ($want === '') {
        $fail("目标自证失败：payload 缺少 {$expectKey}。", 4);
    }

    if ($actual !== $want) {
        $fail("目标自证失败：{$key} = '{$actual}'，期望 '{$want}'。", 4);
    }
}

// ── 会话身份 ───────────────────────────────────────────────────────
// MySQL 侧的 CONNECTION_ID() 是后面在 performance_schema 里定位
// "谁在等谁" 的唯一可靠依据，必须在调用 service 之前取到。
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

if ($role === 'A') {
    // ── worker A ──────────────────────────────────────────────────
    // 在 creating 这件事里暂停：此刻 A 已经拿到 content_items 的行锁、
    // 算完了 decision_no，但 INSERT 尚未发生、事务也未提交。
    // 这是能让 B 撞上同一把行锁的唯一窗口。
    Event::listen(
        'eloquent.creating: '.DuplicateReviewDecision::class,
        static function (DuplicateReviewDecision $pending) use ($runtime, $payload, $connectionId): void {
            $runtime->putJson('paused.json', [
                'role' => 'A',
                'phase' => 'creating_paused',
                'conn_id' => $connectionId,
                'pending_decision_no' => $pending->decision_no,
                'pending_decision' => $pending->decision,
                'paused_at' => microtime(true),
            ]);

            $holdUntil = microtime(true) + (float) ($payload['hold_seconds'] ?? 8.0);

            while (microtime(true) < $holdUntil) {
                if ($runtime->exists('release-A')) {
                    break;
                }

                usleep(20_000);
            }

            $runtime->putJson('released.json', [
                'role' => 'A',
                'conn_id' => $connectionId,
                'resumed_at' => microtime(true),
            ]);
        },
    );
} else {
    // ── worker B ──────────────────────────────────────────────────
    // orchestrator 只在 A 进入 creating 之后才启动 B，这里再做一次确认：
    // 若看不到 A 的暂停信号就绝不出手，否则"并发"会退化成顺序执行，
    // 而顺序执行对本次 Gate 毫无意义（它无法证明任何互斥语义）。
    $paused = $runtime->awaitJson('paused.json', 15.0);

    if ($paused === null) {
        $fail('worker B 未能在 15s 内等到 worker A 的 creating 暂停信号，拒绝以顺序方式执行。', 5);
    }

    $runtime->putJson('b-armed.json', [
        'role' => 'B',
        'conn_id' => $connectionId,
        'armed_at' => microtime(true),
        'a_paused_at' => $paused['paused_at'] ?? null,
    ]);
}

// ── 真实 service 调用 ───────────────────────────────────────────────
$item = ContentItem::query()->findOrFail((int) $payload['content_item_id']);
$callStartedAt = microtime(true);

try {
    $decision = app(DuplicateReviewService::class)->appendDecision($item, (array) $payload['data']);

    $result = [
        'role' => $role,
        'status' => 'ok',
        'decision_id' => $decision->id,
        'decision_no' => $decision->decision_no,
        'decision' => $decision->decision,
        'note' => $decision->note,
    ];
    $exitCode = 0;
} catch (Throwable $e) {
    // 受控的 ValidationException（含 "A concurrent decision was recorded."）也要如实
    // 报回去：它意味着行锁没有起到串行化作用，正是本 Gate 要发现的问题。
    $result = [
        'role' => $role,
        'status' => 'error',
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ];
    $exitCode = 2;
}

$result += [
    'conn_id' => $connectionId,
    'pid' => getmypid(),
    'call_started_at' => $callStartedAt,
    'lock_returned_at' => $lockReturnedAt,
    'returned_at' => microtime(true),
];

$runtime->putJson("result-{$role}.json", $result);

echo '__RESULT__'.json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

if ($exitCode !== 0) {
    fwrite(STDERR, "[worker {$role}] service 抛出异常：{$result['exception']} — {$result['message']}\n");
}

exit($exitCode);
