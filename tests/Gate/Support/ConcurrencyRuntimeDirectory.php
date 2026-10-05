<?php

declare(strict_types=1);

namespace Tests\Gate\Support;

use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * 并发 Gate 的运行目录：两个 worker 之间唯一的协调面。
 *
 * 为什么用文件信号而不是共享内存 / socket / 队列：
 *   - worker 是**独立 PHP 进程**（独立 Laravel bootstrap、独立 PDO 连接），
 *     进程之间不能共享对象，也不应引入额外服务依赖；
 *   - 文件信号可被 orchestrator（测试进程）观测，也便于失败后人工检查现场；
 *   - 这里的信号只用于**编排**（谁启动、什么时候释放），
 *     并发是否真的发生由 LockWaitObserver 从 MySQL 侧独立证明，两者不互相担保。
 *
 * 目录建在系统临时目录下，不会写进仓库。
 */
final class ConcurrencyRuntimeDirectory
{
    /** 父进程放行 worker A 的信号文件名。 */
    public const RELEASE_SIGNAL = 'release-A';

    public function __construct(public readonly string $path) {}

    public static function create(string $prefix = 'qn-mysql84-concurrency'): self
    {
        $path = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR.$prefix.'-'.bin2hex(random_bytes(8));

        if (! @mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new RuntimeException("[concurrency-gate] 无法创建运行目录：{$path}");
        }

        return new self($path);
    }

    public function file(string $name): string
    {
        return $this->path.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * 原子写入：先落同目录临时文件再 rename。
     *
     * 读取方会以毫秒级轮询这些文件，直接 file_put_contents 会让读取方有机会读到
     * 半截内容。rename 在同一文件系统内是原子的，可以保证读到的要么是旧值、要么
     * 是完整的新值。
     */
    public function put(string $name, string $contents): void
    {
        $target = $this->file($name);
        $temporary = $target.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("[concurrency-gate] 写入失败：{$temporary}");
        }

        if (! @rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException("[concurrency-gate] 原子替换失败：{$target}");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putJson(string $name, array $payload): void
    {
        $this->put($name, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function exists(string $name): bool
    {
        clearstatcache(true, $this->file($name));

        return file_exists($this->file($name));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readJson(string $name): ?array
    {
        if (! $this->exists($name)) {
            return null;
        }

        $raw = (string) file_get_contents($this->file($name));

        if (trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("[concurrency-gate] 信号文件 {$name} 不是合法 JSON：{$e->getMessage()}", 0, $e);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 轮询等待某个信号文件出现并可读。
     *
     * @return array<string, mixed>|null 超时返回 null
     */
    public function awaitJson(string $name, float $timeoutSeconds, float $pollSeconds = 0.05): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $payload = $this->readJson($name);

            if ($payload !== null) {
                return $payload;
            }

            usleep((int) max(1, $pollSeconds * 1_000_000));
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putPayload(string $name, array $payload): string
    {
        $this->putJson($name, $payload);

        return $this->file($name);
    }

    /**
     * 释放 worker A：让它在 creating 事件处恢复执行并提交事务。
     *
     * @param  array<string, mixed>  $payload
     */
    public function release(array $payload = []): void
    {
        $this->putJson('release-A', $payload === [] ? ['released_at' => microtime(true)] : $payload);
    }

    public function destroy(): void
    {
        if (! is_dir($this->path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($this->path);
    }
}
