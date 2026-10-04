<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * 从 MySQL 侧独立证明「worker B 正在等待 worker A 持有的行锁」。
 *
 * 为什么不能只用 sleep 推断：
 *   「先 sleep 再让 B 执行」只能说明两件事时间上有先后，B 可能压根没遇到锁
 *   （比如它跑的是另一个库、连接池复用了同一个 connection、或者行锁没生效）。
 *   这类推断会把「顺序执行」也判成「并发成立」，是最典型的假阳性。
 *
 * 因此这里的三条证据都取自 MySQL 服务器自身的状态：
 *
 *   证据 1（决定性）performance_schema.data_lock_waits 出现
 *           requesting = worker B 的连接、blocking = worker A 的连接 的记录。
 *           这条记录是 InnoDB 在真的把 B 挂起等待时才写入的。
 *   证据 2（佐证）    data_locks 里存在 A 已 GRANTED 的 record X 锁；同时用
 *           独立连接的 `FOR UPDATE NOWAIT` 探针撞出 3572，证明该行此刻确实被独占。
 *   证据 3（时间）    B 调用 service 的时刻早于 A 释放的时刻、而 B 拿到锁的时刻
 *           晚于 A 释放的时刻 —— 说明 B 确实被堵了一段时间。
 *
 * 证据 3 单独不足以证明任何事（它仍是时间推断），只在 1、2 成立时作为旁证记录。
 */
final class LockWaitObserver
{
    private const GRANT_HINT = "docker compose -p qn-workbench-mysql84-gate -f docker-compose.mysql-test.yml \\\n"
        ."  exec -T mysql-test mysql -uroot -pqn_test_root_pw \\\n"
        ."  -e \"GRANT SELECT ON performance_schema.* TO 'qn_test'@'%';\"\n"
        ."\n  或等价地执行：scripts/test-mysql.sh grant";

    /**
     * 观测能力预检：读不到 performance_schema 的锁表就没有资格谈「证明了并发」。
     *
     * @throws GateInfrastructureUnavailable 权限不足或表不可用时抛出，调用方必须让它 FAIL
     */
    public function assertObservable(): void
    {
        try {
            DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits');
        } catch (QueryException $e) {
            throw new GateInfrastructureUnavailable(
                "[concurrency-gate] 无法读取 performance_schema.data_lock_waits，并发 Gate **没有通过**。\n"
                .'  原因：'.$e->getMessage()."\n"
                ."  这不是被测代码的问题，而是观测通道不可用；测试不会降级成顺序执行。\n"
                ."  请为 disposable 测试账号补 SELECT 权限后重跑：\n\n".self::GRANT_HINT,
                0,
                $e,
            );
        }

        try {
            DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_locks');
        } catch (QueryException $e) {
            throw new GateInfrastructureUnavailable(
                "[concurrency-gate] 无法读取 performance_schema.data_locks，并发 Gate **没有通过**。\n"
                .'  原因：'.$e->getMessage()."\n\n".self::GRANT_HINT,
                0,
                $e,
            );
        }
    }

    /**
     * 轮询等待「B 在等 A」这条等待记录出现。
     *
     * @return array<string, mixed>|null 命中返回等待行，超时返回 null
     */
    public function waitForRowLockWait(int $requestingConnectionId, int $blockingConnectionId, float $timeoutSeconds, float $pollSeconds = 0.1): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $row = $this->findRowLockWait($requestingConnectionId, $blockingConnectionId);

            if ($row !== null) {
                return $row;
            }

            usleep((int) max(1, $pollSeconds * 1_000_000));
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * 精确匹配等待方向：requesting 必须是 B，blocking 必须是 A。
     *
     * 不做「只要存在等待就行」的宽松匹配——那样会把任何无关会话之间的等待
     * （例如 background purge、其它并行测试）算成本次并发的证据。
     *
     * @return array<string, mixed>|null
     */
    public function findRowLockWait(int $requestingConnectionId, int $blockingConnectionId): ?array
    {
        $row = DB::selectOne(
            <<<'SQL'
            SELECT requesting.PROCESSLIST_ID      AS requesting_connection_id,
                   blocking.PROCESSLIST_ID        AS blocking_connection_id,
                   wait.REQUESTING_ENGINE_LOCK_ID AS requesting_lock_id,
                   wait.BLOCKING_ENGINE_LOCK_ID   AS blocking_lock_id,
                   wait.REQUESTING_ENGINE_TRANSACTION_ID AS requesting_transaction_id,
                   wait.BLOCKING_ENGINE_TRANSACTION_ID  AS blocking_transaction_id
            FROM performance_schema.data_lock_waits AS wait
            JOIN performance_schema.threads AS requesting
              ON requesting.THREAD_ID = wait.REQUESTING_THREAD_ID
            JOIN performance_schema.threads AS blocking
              ON blocking.THREAD_ID = wait.BLOCKING_THREAD_ID
            WHERE requesting.PROCESSLIST_ID = ?
              AND blocking.PROCESSLIST_ID = ?
            SQL,
            [$requestingConnectionId, $blockingConnectionId],
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * 某连接当前已 GRANTED 的锁（佐证 A 真的拿着 record X 锁）。
     *
     * @return list<array<string, mixed>>
     */
    public function grantedLocksHeldBy(int $connectionId): array
    {
        $rows = DB::select(
            <<<'SQL'
            SELECT locks.LOCK_TYPE   AS lock_type,
                   locks.LOCK_MODE   AS lock_mode,
                   locks.LOCK_STATUS AS lock_status,
                   locks.OBJECT_NAME AS object_name,
                   locks.INDEX_NAME  AS index_name
            FROM performance_schema.data_locks AS locks
            JOIN performance_schema.threads AS threads
              ON threads.THREAD_ID = locks.THREAD_ID
            WHERE threads.PROCESSLIST_ID = ?
              AND locks.LOCK_STATUS = 'GRANTED'
            SQL,
            [$connectionId],
        );

        return array_map(static fn (object $row): array => (array) $row, $rows);
    }

    /**
     * NOWAIT 探针：用 orchestrator 自己的连接去抢同一行的 X 锁。
     *
     * 返回 true = 立刻被拒（3572），说明该行此刻确实被别的会话独占持有，
     * 也就是锁真的存在；返回 false = 竟然抢到了，说明根本没有排他锁，
     * 后面的并发结论全部不成立。
     *
     * NOWAIT 保证这行查询不会自己排队等候，因此它不会参与锁竞争、也几乎不占时间。
     */
    public function rowIsLockedForUpdate(string $table, int $primaryKey): bool
    {
        try {
            DB::selectOne("SELECT id FROM {$table} WHERE id = ? FOR UPDATE NOWAIT", [$primaryKey]);
        } catch (QueryException $e) {
            // MySQL 8: 3572 Statement aborted because lock(s) could not be acquired
            // immediately and NOWAIT is set.
            if (str_contains($e->getMessage(), '3572') || str_contains(strtolower($e->getMessage()), 'nowait')) {
                return true;
            }

            throw $e;
        }

        return false;
    }

    /**
     * 失败现场：把当时服务器上的等待关系与持锁情况都打出来，便于判读。
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $requestingConnectionId, int $blockingConnectionId): array
    {
        $waits = DB::select(
            <<<'SQL'
            SELECT requesting.PROCESSLIST_ID AS requesting_connection_id,
                   blocking.PROCESSLIST_ID   AS blocking_connection_id,
                   wait.REQUESTING_ENGINE_LOCK_ID AS requesting_lock_id
            FROM performance_schema.data_lock_waits AS wait
            JOIN performance_schema.threads AS requesting
              ON requesting.THREAD_ID = wait.REQUESTING_THREAD_ID
            JOIN performance_schema.threads AS blocking
              ON blocking.THREAD_ID = wait.BLOCKING_THREAD_ID
            SQL,
        );

        return [
            'expected_wait' => [
                'requesting_connection_id' => $requestingConnectionId,
                'blocking_connection_id' => $blockingConnectionId,
            ],
            'all_current_waits' => array_map(static fn (object $row): array => (array) $row, $waits),
            'blocking_locks' => $this->grantedLocksHeldBy($blockingConnectionId),
            'requesting_locks' => $this->grantedLocksHeldBy($requestingConnectionId),
        ];
    }
}
