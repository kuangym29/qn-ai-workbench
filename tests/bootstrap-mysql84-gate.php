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
 *                   约定的隔离测试目标；DB_URL 必须未设置或为空（任何非空值都会
 *                   整体覆盖上述字段，使逐项校验失效）。
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
//
// 两个不同的读取器，因为它们的"缺失"含义不同：
//   readEnvStrict —— 用于必须精确匹配的目标字段。未定义时返回哨兵值，
//                    让期望值比较报错，而不是静默放行。
//   readEnvNullable —— 用于 DB_URL。未定义返回 null（这是**允许**的形态），
//                    与"非空"严格区分。
$readEnvStrict = static function (string $key): string {
    foreach ([$_ENV, $_SERVER, getenv()] as $bag) {
        if (is_array($bag) && array_key_exists($key, $bag) && $bag[$key] !== false) {
            return (string) $bag[$key];
        }
    }

    return "<unset:{$key}>";
};

// 与 Laravel Env::get 的归一化保持一致：字面 null / (null) 归一为 null，
// 字面 empty / (empty) 归一为 ''。因此"未设置"与"显式空"都应被视为安全。
$readEnvNullable = static function (string $key) use ($readEnvStrict): ?string {
    $raw = $readEnvStrict($key);
    if ($raw === "<unset:{$key}>") {
        return null;
    }
    // <env value="..."> 注入的原始值是字符串，Laravel 之后才做这层归一化。
    // 这里提前做，才能与 config('database.connections.mysql.url') 的结果对齐。
    return match (strtolower($raw)) {
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $raw,
    };
};

foreach ($expected as $key => $want) {
    $got = $readEnvStrict($key);
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

// DB_URL 语义（与 scripts/test-mysql.sh 的 assert_no_db_url 一致）：
//   允许 —— 未设置（null）、空字符串。两者归一化后都不会覆盖任何连接字段。
//   拒绝 —— 任何非空字符串，含纯空格。DB_URL 一旦有值，Laravel 会用它整体覆盖
//           host/port/database/username/password，上面的逐项校验即形同虚设。
//           注意纯空格也算非空：Laravel 不会把 " " 归一化为空，它会当成一个
//           无效但非空的 URL 交给底层驱动，错误现场会远离真正的原因。
//
// 不能写成 `$dbUrl !== ''`：那样未定义时（值为 null）会被误判为"非空"而
// 无故中止，那是一个假失败。
$dbUrl = $readEnvNullable('DB_URL');
if ($dbUrl !== null && $dbUrl !== '') {
    $gateFail("DB_URL='{$dbUrl}' 非空。它会整体覆盖 host/port/database/username/password，
    使上面的逐项校验形同虚设。若要显式留空，请写 DB_URL=（空值）或直接删除该行。");
}
$gatePass('DB_URL 未设置或为空（逐项校验有效）');

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
