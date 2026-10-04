<?php

namespace Tests\Gate;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Release Gate 自证：确认本次测试进程真的连在 MySQL 8.4 测试库上。
 *
 * 为什么单独放在 tests/Gate 而不是 tests/Feature：
 * 这个类只在 phpunit.mysql84.xml 下被加载（该配置多注册了一个 Gate
 * testsuite），日常开发用的 phpunit.xml 不会扫到这里。于是：
 *   - MySQL Gate 跑到它 → 硬断言 driver / database / version，任何不符即失败；
 *   - 日常 SQLite 套件 → 完全看不到它，不会让主线基线多出一个 skip 或 fail。
 *
 * 有了它，"SQLite 跑绿"在结构上就无法被当成 Gate 通过：真正跑 MySQL 时
 * 这条断言会检查 Laravel 实际建立的连接，而不是配置文件里写了什么。
 */
class Mysql84DriverTest extends TestCase
{
    use RefreshDatabase;

    /** Gate 约定的隔离测试目标，与 scripts/test-mysql.sh 顶部常量一致。 */
    private const EXPECT_HOST = '127.0.0.1';

    private const EXPECT_PORT = '3399';

    private const EXPECT_DATABASE = 'qn_workbench_test';

    private const EXPECT_USERNAME = 'qn_test';

    public function test_the_active_connection_is_the_isolated_mysql_test_database(): void
    {
        $config = config('database.default');
        $this->assertSame(
            'mysql',
            $config,
            'Gate 必须在 mysql 上运行。若此处是 sqlite，说明用的是 phpunit.xml 而非 phpunit.mysql84.xml，'
            .'本次结果不能作为 Release Gate 证据。',
        );

        $mysql = config('database.connections.mysql');
        $this->assertSame(self::EXPECT_HOST, $mysql['host']);
        $this->assertSame(self::EXPECT_PORT, (string) $mysql['port']);
        $this->assertSame(self::EXPECT_DATABASE, $mysql['database']);
        $this->assertSame(self::EXPECT_USERNAME, $mysql['username']);
        $this->assertNull(
            $mysql['url'] ?? null,
            'DB_URL 非空会整体覆盖逐项校验，必须为空。',
        );

        // 配置对不代表连上了；问服务器本人。
        $pdo = DB::connection()->getPdo();
        $this->assertInstanceOf(\PDO::class, $pdo);

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $this->assertStringStartsWith(
            '8.4.',
            $version,
            "Release Gate 只认可 MySQL 8.4.x，实际连接到的版本是 {$version}。",
        );

        $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertSame(
            self::EXPECT_DATABASE,
            $currentDb,
            "实际连上的库是 {$currentDb}，不是约定的隔离测试库。",
        );

        $user = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $this->assertStringContainsString(
            self::EXPECT_USERNAME,
            $user,
            "连接使用的账号是 {$user}，不是约定的测试账号 qn_test。",
        );
    }

    public function test_lock_for_update_is_backed_by_innodb(): void
    {
        // SQLite 没有行锁，D11 的 decision_no 串行化依赖 InnoDB 的
        // lockForUpdate()。这里确认引擎真的是 InnoDB，而不是碰巧同名。
        $engine = DB::selectOne(
            "SELECT ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_items'"
        );

        $this->assertNotNull($engine, 'content_items 表不存在，migration 可能未跑。');
        $this->assertSame(
            'InnoDB',
            $engine->ENGINE,
            "content_items 引擎为 {$engine->ENGINE}；lockForUpdate 的行锁语义依赖 InnoDB。",
        );
    }
}
