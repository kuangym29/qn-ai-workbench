<?php

/**
 * Release Gate 专用 bootstrap：证明本次测试真的跑在 MySQL 8.4 测试库上。
 *
 * 为什么需要它：
 * 仓库根部的 phpunit.xml 把 DB_CONNECTION 写死为 sqlite、DB_DATABASE 写死为
 * :memory:。Laravel 的 <env> 会在测试启动时覆盖 .env 带进来的值，所以
 * `php artisan test --env=mysql-testing` 表面看指定了 MySQL，实际连的仍是
 * SQLite 内存库 —— 全绿也证明不了任何 MySQL 行为。
 *
 * 这里做两层证明，任何一层不通过都在第一个测试用例之前就退出：
 *
 *   闸 1（配置层）：生效的 driver / host / port / database / user 必须逐项等于
 *                   约定的隔离测试目标，且 DB_URL 为空（否则单 URL 会覆盖上面
 *                   全部字段，使逐项校验失效）。
 *   闸 2（连接层）：真正建立 PDO 连接，向服务器问 version() 与 database()。
 *                   只有连上真的 MySQL 8.4 的 qn_workbench_test 才会走到这里。
 *
 * 之后 Laravel 用同一组 env 建连，因此测试进程内跑的必然是这个库。
 * 未通过时退出码非 0，Gate 不会被误判为通过。
 */

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

/** 约定的隔离测试目标，与 scripts/test-mysql.sh 顶部的常量逐字一致。 */
$expected = [
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3399',
    'DB_DATABASE' => 'qn_workbench_test',
    'DB_USERNAME' => 'qn_test',
    'DB_PASSWORD' => 'qn_test_pw',
];

$gateFail = function (string $message): void {
    fwrite(STDERR, "\n  [GATE-FAIL] {$message}\n");
    fwrite(STDERR, "MYSQL84_GATE_DRIVER_NOT_PROVEN\n");
    exit(1);
};

$gatePass = function (string $line): void {
    fwrite(STDOUT, "  [GATE-OK] {$line}\n");
};

// ── 闸 1：配置层逐项校验 ─────────────────────────────────────────────
// 读 phpunit.xml 注入的 $_ENV/$_SERVER。Laravel 的 <env> 默认写入这两处。
$readEnv = static function (string $key) use (&$gateFail): string {
    foreach ([$_ENV, $_SERVER, getenv()] as $bag) {
        if (is_array($bag) && array_key_exists($key, $bag) && $bag[$key] !== false) {
            return (string) $bag[$key];
        }
    }
    // 缺失时返回哨兵值，交由期望值比较报错，而不是静默放行。
    return "<unset:{$key}>";
};

foreach ($expected as $key => $want) {
    $got = $readEnv($key);
    if ($got !== $want) {
        $gateFail("{$key} 期望 '{$want}'，实际 '{$got}'。
    拒绝在未证明的数据库上运行测试。请通过 scripts/test-mysql.sh 使用
    phpunit.mysql84.xml；直接 php artisan test 会退回 phpunit.xml 的 sqlite。");
    }
    if ($key === 'DB_PASSWORD') {
        $gatePass("{$key} = <约定的本地测试常量>");
    } else {
        $gatePass("{$key} = {$got}");
    }
}

$dbUrl = $readEnv('DB_URL');
if ($dbUrl !== '') {
    $gateFail("DB_URL='{$dbUrl}' 非空。它会整体覆盖 host/port/database/username/password，
    使上面的逐项校验形同虚设。");
}
$gatePass('DB_URL 为空（逐项校验有效）');

// ── 闸 2：连接层，向服务器自证身份 ───────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $expected['DB_HOST'],
    $expected['DB_PORT'],
    $expected['DB_DATABASE'],
);

try {
    $pdo = new PDO($dsn, $expected['DB_USERNAME'], $expected['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (PDOException $e) {
    $gateFail("无法连接隔离测试库 {$expected['DB_HOST']}:{$expected['DB_PORT']}：{$e->getMessage()}
    请先执行 scripts/test-mysql.sh up 启动 disposable 容器。");
}

$gatePass('PDO 连接已建立');

$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (! str_starts_with($version, '8.4.')) {
    $gateFail("服务器版本 '{$version}' 不是 MySQL 8.4.x。Release Gate 只认可 8.4。");
}
$gatePass("服务器自报 version = {$version}");

$currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($currentDb !== $expected['DB_DATABASE']) {
    $gateFail("当前库 '{$currentDb}' 不是约定的 '{$expected['DB_DATABASE']}'。");
}
$gatePass("当前库 = {$currentDb}");

$engine = (string) $pdo
    ->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'duplicate_review_decisions'")
    ->fetchColumn();

if ($engine !== '' && strcasecmp($engine, 'InnoDB') !== 0) {
    // 表还不存在（首次 migrate 前）是正常状态；这里存在且不是 InnoDB 才是问题。
    $gateFail("duplicate_review_decisions 引擎为 '{$engine}'，期望 InnoDB。
    lockForUpdate 的行锁语义依赖 InnoDB。");
}
if ($engine !== '') {
    $gatePass("duplicate_review_decisions 引擎 = {$engine}");
}

$isolation = (string) $pdo->query('SELECT @@transaction_isolation')->fetchColumn();
$gatePass("transaction_isolation = {$isolation}");

fwrite(STDOUT, "MYSQL84_GATE_DRIVER_PROVEN driver=mysql host={$expected['DB_HOST']} port={$expected['DB_PORT']} db={$expected['DB_DATABASE']} version={$version}\n");
