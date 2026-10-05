<?php

declare(strict_types=1);

namespace Tests\Gate;

use Tests\Gate\Support\PauseWatchdogTimeout;
use Tests\Gate\Support\WorkerPauseGate;
use Tests\TestCase;

/**
 * worker A 暂停闸门的 watchdog 回归检查（Gate-only，不需要数据库）。
 *
 * 要钉住的是 Blocker 2 的语义：**超时永远不等于放行**。
 *
 * 旧实现的循环是"到期就跳出"，跳出后 A 继续 INSERT 并 COMMIT —— 于是父进程
 * 一旦没在窗口内写 release 信号，本该失败的编排会悄悄退化成"A 先提交、B 顺序
 * 执行"的假并发，最后还可能报出一份漂亮的"编号已串行化"结论。这类缺陷靠人读
 * 代码很容易再次引入，所以在这里用纯逻辑单测钉死。
 *
 * WorkerPauseGate 不碰文件系统也不碰数据库，只等一个布尔条件，因此本类可以在
 * 没有 MySQL 的机器上完整运行。
 */
final class WorkerPauseWatchdogTest extends TestCase
{
    public function test_release_signal_lets_the_worker_continue(): void
    {
        $calls = 0;

        WorkerPauseGate::await(static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 5.0);

        $this->assertSame(1, $calls, '已放行时必须立即返回，不做无谓轮询。');
    }

    public function test_release_arriving_later_is_still_honoured(): void
    {
        $calls = 0;

        WorkerPauseGate::await(static function () use (&$calls): bool {
            $calls++;

            return $calls >= 3;
        }, 5.0, 0.01);

        $this->assertGreaterThanOrEqual(3, $calls, '应当持续轮询直到收到信号。');
    }

    public function test_watchdog_timeout_throws_instead_of_releasing(): void
    {
        $started = microtime(true);

        try {
            WorkerPauseGate::await(static fn (): bool => false, 0.1, 0.01);
            $this->fail('watchdog 到期必须抛异常，绝不能当成一次正常放行。');
        } catch (PauseWatchdogTimeout $timeout) {
            $this->assertStringContainsString('回滚', $timeout->getMessage(), '异常必须说明会导致回滚。');
            $this->assertStringContainsString('不 INSERT', $timeout->getMessage());
        }

        $this->assertGreaterThanOrEqual(0.09, microtime(true) - $started, '应当确实等到了超时才抛。');
    }

    /**
     * 边界：timeout 为 0 时同样必须走异常路径，不能因为"循环一次都不进"就放行。
     */
    public function test_zero_timeout_never_releases(): void
    {
        $this->expectException(PauseWatchdogTimeout::class);

        WorkerPauseGate::await(static fn (): bool => false, 0.0);
    }

    /**
     * 边界：信号恰好在超时瞬间到达也必须被承认（最后一次复查），避免误伤正常路径。
     */
    public function test_signal_at_the_boundary_is_still_accepted(): void
    {
        $calls = 0;

        WorkerPauseGate::await(static function () use (&$calls): bool {
            $calls++;

            return $calls >= 2;
        }, 0.001, 0.01);

        $this->assertGreaterThanOrEqual(2, $calls);
    }
}
