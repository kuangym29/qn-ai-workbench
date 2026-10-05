<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use RuntimeException;

/**
 * worker A 在 `creating` 阶段等待父进程显式释放时的 watchdog 超时。
 *
 * 抛出它意味着"A 没能等到放行"，因此当前事务必须回滚。
 * 它**不是**一次正常释放：绝不能被捕获后当作"可以继续提交"处理。
 */
final class PauseWatchdogTimeout extends RuntimeException
{
    public static function after(float $timeoutSeconds): self
    {
        return new self(
            "[worker A] creating 暂停 watchdog 到期（{$timeoutSeconds}s）仍未收到释放信号。"
            .'事务必须回滚：不 INSERT、不 COMMIT，worker 非 0 退出。'
        );
    }
}
