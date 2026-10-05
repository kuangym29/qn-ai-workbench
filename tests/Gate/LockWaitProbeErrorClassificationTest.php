<?php

declare(strict_types=1);

namespace Tests\Gate;

use Illuminate\Database\QueryException;
use PDOException;
use Tests\Gate\Support\LockWaitObserver;
use Tests\TestCase;

/**
 * NOWAIT 探针的**错误分类回归检查**（Gate-only，不需要真实数据库）。
 *
 * 背景：并发 Gate 用 `SELECT ... FOR UPDATE NOWAIT` 探测"这一行此刻是否真的被
 * 别的会话独占持有"。判定必须极其严格——它是一条**证明**排他锁存在的证据，
 * 一旦把别的错误误判成"锁成立"，就会把一个观测故障粉饰成"并发已证明"。
 *
 * 历史缺陷：旧实现用 `str_contains($message, '3572') || str_contains(strtolower($message), 'nowait')`
 * 判定。但 QueryException 的 message 会带上原始 SQL，而这条 SQL 本身含 NOWAIT，
 * 于是 1142（权限不足）、1064（语法错误）、2006（连接断开）全都会被判成"锁成立"。
 *
 * 现在的规则：**只有 MySQL driver code 3572** 才算锁冲突。driver code 取自
 * PDO 的 errorInfo[1]；SQLSTATE 不能用（3572/1142/1064/2006 分别是 HY000/42000/
 * HY000，3572 与 2006 甚至同为 HY000）。
 *
 * 本类只注册在 phpunit.mysql84.xml 的 Gate testsuite 下，主线 phpunit.xml 不含
 * tests/Gate 目录，因此不会给普通 SQLite suite 增加任何测试或 skip。
 * 它自身不连接数据库——所有异常都是构造出来的。
 */
final class LockWaitProbeErrorClassificationTest extends TestCase
{
    private const PROBE_SQL = 'select id from `content_items` where `id` = ? for update nowait';

    /**
     * 构造一个与真实驱动行为一致的 QueryException。
     *
     * @param  array<int, mixed>  $bindings
     * @param  array{0: string, 1: int, 2: string}|null  $errorInfo
     */
    private function probeError(string $sqlState, int $driverCode, string $driverMessage, ?array $errorInfo = null): QueryException
    {
        $pdo = new PDOException("SQLSTATE[{$sqlState}]: General error: {$driverCode} {$driverMessage}");
        $pdo->errorInfo = $errorInfo ?? [$sqlState, $driverCode, $driverMessage];

        // QueryException 的 message 由 Laravel 拼装，会带上 $sql —— 也就天然带着 NOWAIT。
        return new QueryException('mysql', self::PROBE_SQL, [1], $pdo);
    }

    /**
     * 沿异常链取 SQLSTATE（errorInfo[0]），用于构造"SQLSTATE 相同"的对照前提。
     */
    private function sqlStateOf(QueryException $error): ?string
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException && is_array($current->errorInfo) && isset($current->errorInfo[0])) {
                return (string) $current->errorInfo[0];
            }
        }

        return null;
    }

    public function test_nowait_conflict_3572_is_the_only_accepted_lock_signal(): void
    {
        $error = $this->probeError(
            'HY000',
            3572,
            'Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set.',
        );

        $this->assertSame(3572, LockWaitObserver::driverErrorCode($error), '应能从 errorInfo[1] 取到 driver code。');
        $this->assertTrue(LockWaitObserver::isLockConflict($error), '3572 必须判为锁冲突。');
        $this->assertTrue(
            LockWaitObserver::lockConflictOrRethrow($error),
            '3572 是唯一允许返回 true 的情形。',
        );
    }

    /**
     * 1142：SELECT command denied。
     *
     * 这是最危险的一个——它恰好发生在"没给 qn_test 授 performance_schema 权限"时，
     * 而 message 里同样含 NOWAIT。旧实现会因此把权限故障报成"锁成立"。
     */
    public function test_permission_error_1142_is_not_treated_as_a_lock(): void
    {
        $error = $this->probeError('42000', 1142, "SELECT command denied to user 'qn_test'@'%' for table 'content_items'");

        $this->assertSame(1142, LockWaitObserver::driverErrorCode($error));
        $this->assertFalse(LockWaitObserver::isLockConflict($error), '1142 是权限错误，绝不能当成锁成立。');
        $this->assertStringContainsStringIgnoringCase('nowait', $error->getMessage(), '前提：message 里确实含 NOWAIT（旧实现在这里会误判）。');

        $this->expectException(QueryException::class);
        LockWaitObserver::lockConflictOrRethrow($error);
    }

    /**
     * SQLSTATE 相同、driver code 不同：证明判定不依赖 SQLSTATE。
     *
     * MySQL 对 3572 与 2006 都返回 HY000，只看 SQLSTATE 必然混淆。
     */
    public function test_same_sqlstate_different_driver_code_is_discriminated(): void
    {
        $lockConflict = $this->probeError('HY000', 3572, 'Statement aborted because lock(s) could not be acquired immediately and NOWAIT is set.');
        $connectionLost = $this->probeError('HY000', 2006, 'MySQL server has gone away');

        $this->assertSame(
            $this->sqlStateOf($lockConflict),
            $this->sqlStateOf($connectionLost),
            '前提：两者的 SQLSTATE 必须真的相同。',
        );
        $this->assertNotNull($this->sqlStateOf($lockConflict), '前提：SQLSTATE 可读。');

        $this->assertTrue(LockWaitObserver::isLockConflict($lockConflict));
        $this->assertFalse(LockWaitObserver::isLockConflict($connectionLost), '同为 HY000，但 2006 不是锁冲突。');

        $this->expectException(QueryException::class);
        LockWaitObserver::lockConflictOrRethrow($connectionLost);
    }

    /**
     * 1064（语法错误）与 2006（连接断开）都必须原样抛出。
     */
    public function test_syntax_and_connection_errors_are_rethrown_untouched(): void
    {
        foreach ([['42000', 1064, 'You have an error in your SQL syntax'], ['HY000', 2006, 'MySQL server has gone away']] as [$state, $code, $text]) {
            $error = $this->probeError($state, $code, $text);

            $this->assertFalse(LockWaitObserver::isLockConflict($error), "driver code {$code} 不得判为锁冲突。");

            try {
                LockWaitObserver::lockConflictOrRethrow($error);
                $this->fail("driver code {$code} 应当被重新抛出，而不是返回 true。");
            } catch (QueryException $rethrown) {
                $this->assertSame($error, $rethrown, '必须是原异常，不得吞掉或替换。');
            }
        }
    }

    /**
     * 拿不到 driver code 时必须保守：判为"不是锁冲突"，进而让调用方抛出。
     */
    public function test_missing_error_info_is_not_treated_as_a_lock(): void
    {
        $pdo = new PDOException('SQLSTATE[HY000]: General error: 3572 Statement aborted ... NOWAIT is set.');
        $pdo->errorInfo = null;
        $error = new QueryException('mysql', self::PROBE_SQL, [1], $pdo);

        $this->assertNull(LockWaitObserver::driverErrorCode($error), 'errorInfo 缺失时应返回 null。');
        $this->assertFalse(
            LockWaitObserver::isLockConflict($error),
            'message 里明明有 3572 和 NOWAIT，但取不到 driver code 就不能当锁成立——这正是旧实现的坑。',
        );

        $this->expectException(QueryException::class);
        LockWaitObserver::lockConflictOrRethrow($error);
    }
}
