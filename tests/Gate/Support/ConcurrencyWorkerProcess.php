<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use RuntimeException;

/**
 * 一个独立的 worker 进程。
 *
 * 关键点：worker 必须是**真正的独立进程**，具备自己的
 *   - PHP 解释器实例（PHP_BINARY 启动子进程），
 *   - Laravel bootstrap（worker 脚本内 Bound 自己的 Application），
 *   - PDO 连接（因此 MySQL 侧会看到一个独立的 CONNECTION_ID / thread）。
 *
 * 只有在这一层成立的前提下，「两个会话争同一行」才是真的并发；
 * 同一个进程里开两条连接或两段闭包调用都证明不了会话级互斥。
 */
final class ConcurrencyWorkerProcess
{
    /** @var resource|null */
    private $handle = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $stdout = '';

    private string $stderr = '';

    public function __construct(
        public readonly string $role,
        public readonly string $commandLine,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function start(ConcurrencyRuntimeDirectory $runtime, string $role, array $payload): self
    {
        $payloadPath = $runtime->putPayload("worker-{$role}.payload.json", $payload);
        $workerScript = base_path('tests/Gate/Support/concurrency_worker.php');

        if (! is_file($workerScript)) {
            throw new RuntimeException("[concurrency-gate] 缺少 worker 脚本：{$workerScript}");
        }

        $command = [PHP_BINARY, $workerScript, $payloadPath];
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $handle = @proc_open($command, $descriptors, $pipes, base_path(), self::environment());

        if (! is_resource($handle)) {
            throw new RuntimeException("[concurrency-gate] worker {$role} 启动失败。命令：".implode(' ', array_map('strval', $command)));
        }

        $process = new self($role, implode(' ', array_map('strval', $command)));
        $process->handle = $handle;
        $process->pipes = $pipes;

        // 管道必须非阻塞：worker 可能长时间停在锁等待上，阻塞读取会把整个测试挂死。
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_set_blocking($pipe, false);
            }
        }

        return $process;
    }

    /**
     * worker 的运行环境：显式带上「实际生效」的数据库连接配置。
     *
     * 取自 orchestrator 进程内 config() 的真实值，而不是重新读一遍 .env——
     * Gate bootstrap 已经证明 orchestrator 连的就是隔离测试库，把同一组值传下去
     * 可以保证 worker 连到同一个库；worker 侧还会再自证一次。
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $environment = [];

        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value) && $key !== '') {
                $environment[$key] = $value;
            }
        }

        $connection = config('database.connections.mysql') ?? [];

        return array_merge($environment, [
            'APP_ENV' => 'mysql-testing',
            'APP_DEBUG' => 'true',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) ($connection['host'] ?? ''),
            'DB_PORT' => (string) ($connection['port'] ?? ''),
            'DB_DATABASE' => (string) ($connection['database'] ?? ''),
            'DB_USERNAME' => (string) ($connection['username'] ?? ''),
            'DB_PASSWORD' => (string) ($connection['password'] ?? ''),
            // 空值：不容许单 URL 覆盖上面的逐项配置（与 Gate 其余各处同一语义）。
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LOG_CHANNEL' => 'stderr',
        ]);
    }

    public function drainOutput(): void
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $descriptor => $property) {
            $pipe = $this->pipes[$descriptor] ?? null;

            if (! is_resource($pipe) || feof($pipe)) {
                continue;
            }

            $chunk = fread($pipe, 8192);

            if (is_string($chunk) && $chunk !== '') {
                $this->{$property} .= $chunk;
            }
        }
    }

    public function stdout(): string
    {
        $this->drainOutput();

        return $this->stdout;
    }

    public function stderr(): string
    {
        $this->drainOutput();

        return $this->stderr;
    }

    public function isRunning(): bool
    {
        return is_resource($this->handle) && proc_get_status($this->handle)['running'] === true;
    }

    /**
     * 等待进程自然结束。
     *
     * @return bool true = 已退出并拿到退出码；false = 超时仍在运行
     */
    public function waitForExit(float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $this->drainOutput();

            if (! $this->isRunning()) {
                // 退出后再收一次，避免丢掉最后的输出。
                $this->drainOutput();

                return true;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function exitCode(): ?int
    {
        if (! is_resource($this->handle)) {
            return null;
        }

        $status = proc_get_status($this->handle);

        return $status['running'] === true ? null : $status['exitcode'];
    }

    public function kill(): void
    {
        if (is_resource($this->handle)) {
            @proc_terminate($this->handle, 9);
            usleep(100_000);
        }

        foreach ($this->pipes as $descriptor => $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
                unset($this->pipes[$descriptor]);
            }
        }

        if (is_resource($this->handle)) {
            @proc_close($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'role' => $this->role,
            'exit_code' => $this->exitCode(),
            'running' => $this->isRunning(),
            'stdout_tail' => mb_substr($this->stdout(), -1200),
            'stderr_tail' => mb_substr($this->stderr(), -1200),
        ];
    }
}
