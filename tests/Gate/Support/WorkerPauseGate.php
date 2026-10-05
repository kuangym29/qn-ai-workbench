<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use Tests\Gate\WorkerPauseWatchdogTest;

/**
 * worker A 的"暂停闸门"：只有父进程明确放行才允许继续。
 *
 * 为什么单独抽出来：
 *   Blocker 2 的实质风险是"超时被当成正常释放"——watchdog 一到点就跳出循环，
 *   于是 A 继续 INSERT 并 COMMIT，整场编排就悄悄退化成"A 先提交、B 顺序执行"的
 *   假并发。这类缺陷光靠读代码很容易再次引入，所以把"超时必须抛异常"做成
 *   一个可单测的纯逻辑单元，并由 {@see WorkerPauseWatchdogTest} 钉住。
 *
 * 这里不做任何 IO 与数据库操作，只等一个布尔条件；因此它可以在没有 MySQL 的
 * 机器上被完整验证。
 */
final class WorkerPauseGate
{
    /**
     * 等待放行信号。
     *
     * @param  callable(): bool  $isReleased  返回 true 表示父进程已放行
     *
     * @throws PauseWatchdogTimeout 超时仍未放行 —— 调用方必须让事务回滚
     */
    public static function await(callable $isReleased, float $timeoutSeconds, float $pollSeconds = 0.02): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            if ($isReleased()) {
                return;
            }

            usleep((int) max(1, $pollSeconds * 1_000_000));
        } while (microtime(true) < $deadline);

        // 最后再问一次：避免恰好在循环退出瞬间收到信号却被误判为超时。
        if ($isReleased()) {
            return;
        }

        throw PauseWatchdogTimeout::after($timeoutSeconds);
    }
}
