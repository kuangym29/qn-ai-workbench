<?php

declare(strict_types=1);

namespace Tests\Gate;

use App\Enums\PageType;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Gate\Support\ConcurrencyRuntimeDirectory;
use Tests\Gate\Support\ConcurrencyWorkerProcess;
use Tests\Gate\Support\LockWaitObserver;
use Tests\TestCase;

/**
 * MySQL 8.4 Release Gate —— DuplicateReview 决策编号的**真实并发**串行化验证。
 *
 * 要证明的对象是 DuplicateReviewService::appendDecision() 里那条链路：
 *
 *      DB::transaction
 *        └─ ContentItem::lockForUpdate()      ← 会话级排他行锁
 *        └─ max(decision_no) + 1              ← 编号计算（在锁内）
 *        └─ DuplicateReviewDecision::create() ← INSERT（在锁内）
 *
 * 以及它依赖的两件事：
 *   1. MySQL 的行锁真的会让第二个会话**停在 lockForUpdate 上等待**（SQLite 是整库写锁，
 *      会把这条语义掩盖掉，因此这条 Gate 只能在 MySQL 8.4 上跑）；
 *   2. 等待结束后第二个会话能读到第一个会话提交的 #1，从而算出 #2
 *      （REPEATABLE READ 下这一点并不显然，取决于 read view 建立的时机）。
 *
 * 为什么必须两个独立进程：
 *   同一进程内的两次调用共享连接与会话，MySQL 侧只有一个 CONNECTION_ID，
 *   既不会形成锁等待，也无法在 performance_schema 里区分"谁在等谁"。
 *   这里每个 worker 都是 PHP_BINARY 拉起的独立进程，有各自的 Laravel bootstrap
 *   与 PDO 连接。
 *
 * 本类只注册在 phpunit.mysql84.xml 的 Gate testsuite 下；主线 phpunit.xml 不加载它，
 * 因此普通 SQLite suite 的测试数与断言数保持不变。
 */
final class Mysql84ConcurrencyTest extends TestCase
{
    /** 与 tests/bootstrap-mysql84-gate.php、scripts/test-mysql.sh 顶部常量逐字一致。 */
    private const EXPECTED_DATABASE = 'qn_workbench_test';

    /** 与 docs/DEV-D08 查重基线一致：两个版本同一句文本，才能成为 candidate。 */
    private const SHARED_TEXT = '同一句测试文本';

    private const NOTE_A = 'worker-a-first-decision';

    private const NOTE_B = 'worker-b-second-decision';

    // ── 时间预算 ─────────────────────────────────────────────────────
    //
    // 只有**一个**窗口受 MySQL 的 --innodb-lock-wait-timeout=10 约束：
    // 「B 开始在 lockForUpdate 上等待」到「A 收到 release 并提交」这段时间。
    // B 一旦等满 10s，MySQL 会自己抛 Lock wait timeout exceeded，把真正的
    // 编排失败（没观测到等待、锁没生效、连接串了）掩盖成一堆超时噪音。
    //
    // 因此：
    //   - WAIT_LOCK_OBSERVE_SECONDS 必须明显小于 10s。它是父进程的观测窗口，
    //     命中后立刻 release，所以它几乎就是上面那个窗口的长度。留一倍以上余量。
    //   - A_PAUSE_TIMEOUT_SECONDS 是 worker A 的 watchdog，只用来防止父进程
    //     异常退出后 A 永久占着行锁。它**可以**（也应该）大于 10s，因为它是
    //     兜底而不是流程；而且它到期绝不正常提交，只抛异常回滚。
    //   - WAIT_A_PAUSED_SECONDS / WAIT_B_CONNECTION_SECONDS 发生在任何锁等待之前
    //     （A 还没被等、B 还没调 service），不受该超时约束。
    private const WAIT_LOCK_OBSERVE_SECONDS = 5.0;

    private const A_PAUSE_TIMEOUT_SECONDS = 30.0;

    private const WAIT_A_PAUSED_SECONDS = 20.0;

    private const WAIT_B_CONNECTION_SECONDS = 10.0;

    private const WAIT_WORKER_EXIT_SECONDS = 25.0;

    /**
     * 两个 worker 对同一个 ContentItem、同一个四字段 pairing 争夺编号。
     *
     * 编排顺序：
     *   1. A 启动 → 取锁 → 编号算完 → 停在 creating 事件（事务未提交）
     *   2. 确认此刻该行已被独占（NOWAIT 探针）
     *   3. B 启动 → 撞上同一把行锁并被挂起
     *   4. 从 performance_schema 观测到 "requesting=B, blocking=A"
     *   5. 释放 A → A 提交 #1 → B 获锁 → 读到 #1 → 提交 #2
     */
    public function test_two_independent_workers_serialize_decision_numbers_under_a_real_row_lock(): void
    {
        $this->assertMysql84Target();

        // 观测能力预检。读不到 performance_schema 的锁表就不是"observe 失败"，
        // 而是**没有证据**，此时必须 FAIL，不允许退化成顺序执行。
        $observer = new LockWaitObserver;
        $observer->assertObservable();

        [$project, $item, $queryVersion, $matchVersion] = $this->createScenario();

        // worker 的写入是真实提交的，所以这里刻意**不使用 RefreshDatabase**：
        // 事务包裹的数据 worker 看不见，那会直接把并发场景变成空跑。
        // 隔离改为：每次运行建一个随机临时运行目录 + 结束时按 project 显式清理。
        $runtime = ConcurrencyRuntimeDirectory::create();
        $workerA = null;
        $workerB = null;

        try {
            $pairing = [
                'query_page_version_id' => $queryVersion->id,
                'query_field' => 'page_title',
                'match_page_version_id' => $matchVersion->id,
                'match_field' => 'page_title',
            ];

            $workerA = ConcurrencyWorkerProcess::start($runtime, 'A', [
                'role' => 'A',
                'runtime_dir' => $runtime->path,
                'content_item_id' => $item->id,
                'data' => [...$pairing, 'decision' => 'confirmed_duplicate', 'note' => self::NOTE_A],
                'pause_timeout_seconds' => self::A_PAUSE_TIMEOUT_SECONDS,
                'expected' => $this->expectedTarget(),
            ]);

            $paused = $runtime->awaitJson('paused.json', self::WAIT_A_PAUSED_SECONDS);

            if ($paused === null) {
                $this->fail(
                    '[concurrency-gate] worker A 未能在 '.self::WAIT_A_PAUSED_SECONDS."s 内进入 creating 暂停，并发窗口没有形成。\n"
                    .json_encode($workerA->describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                );
            }

            $connectionA = (int) ($runtime->readJson('conn-A.json')['conn_id'] ?? 0);
            $this->assertGreaterThan(0, $connectionA, 'worker A 未报告自己的 CONNECTION_ID()，无法定位锁关系。');

            // 证据 2：此刻 content_items 这一行确实处于排他锁定状态。
            // NOWAIT 探针来自 orchestrator 自己的第三条连接，撞出 3572 才算数。
            $rowLockedDuringPause = $observer->rowIsLockedForUpdate('content_items', (int) $item->id);
            $locksHeldByA = $observer->grantedLocksHeldBy($connectionA);

            // worker B 必须在 A 暂停之后才启动，否则会退化成顺序执行。
            $workerB = ConcurrencyWorkerProcess::start($runtime, 'B', [
                'role' => 'B',
                'runtime_dir' => $runtime->path,
                'content_item_id' => $item->id,
                'data' => [...$pairing, 'decision' => 'ignored', 'note' => self::NOTE_B],
                'expected' => $this->expectedTarget(),
            ]);

            $connB = $runtime->awaitJson('conn-B.json', self::WAIT_B_CONNECTION_SECONDS);

            if ($connB === null) {
                $this->fail(
                    '[concurrency-gate] worker B 未能在 '.self::WAIT_B_CONNECTION_SECONDS."s 内建立连接。\n"
                    .json_encode($workerB->describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                );
            }

            $connectionB = (int) ($connB['conn_id'] ?? 0);
            $this->assertNotSame($connectionA, $connectionB, '两个 worker 必须是两个 MySQL 会话；CONNECTION_ID 相同说明进程隔离没生效。');

            // 证据 1（决定性）：data_lock_waits 里必须出现 requesting=B、blocking=A 的记录。
            $wait = $observer->waitForRowLockWait($connectionB, $connectionA, self::WAIT_LOCK_OBSERVE_SECONDS);

            if ($wait === null) {
                $this->fail(
                    "[concurrency-gate] 未观测到 worker B 等待 worker A 的行锁，Gate **没有通过**。\n"
                    ."  这意味着无法证明本次是真实并发（可能是行锁没生效、或 B 根本没走到加锁那一步）。\n"
                    ."  服务器现场：\n"
                    .json_encode($observer->snapshot($connectionB, $connectionA), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n"
                    .'  worker A：'.json_encode($workerA->describe(), JSON_UNESCAPED_UNICODE)."\n"
                    .'  worker B：'.json_encode($workerB->describe(), JSON_UNESCAPED_UNICODE),
                );
            }

            $runtime->release();

            foreach ([$workerA, $workerB] as $worker) {
                if (! $worker->waitForExit(self::WAIT_WORKER_EXIT_SECONDS)) {
                    $this->fail(
                        "[concurrency-gate] worker {$worker->role} 在 ".self::WAIT_WORKER_EXIT_SECONDS."s 内未退出。\n"
                        .json_encode($worker->describe(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    );
                }
            }

            $resultA = $runtime->readJson('result-A.json');
            $resultB = $runtime->readJson('result-B.json');
            $resumed = $runtime->readJson('released.json');

            // ── worker 是否正常退出（无 500 / 无未捕获异常）──────────────
            $this->assertSame(0, $workerA->exitCode(), 'worker A 未正常退出。');
            $this->assertSame(0, $workerB->exitCode(), 'worker B 未正常退出。');
            $this->assertNotNull($resultA, 'worker A 未产出结果文件。');
            $this->assertNotNull($resultB, 'worker B 未产出结果文件。');
            $this->assertSame('ok', $resultA['status'] ?? null, 'worker A 的业务调用失败：'.json_encode($resultA, JSON_UNESCAPED_UNICODE));
            $this->assertSame('ok', $resultB['status'] ?? null, 'worker B 的业务调用失败：'.json_encode($resultB, JSON_UNESCAPED_UNICODE));

            // 走到 UniqueConstraintViolationException → 422 那条受控分支说明行锁
            // 没能串行化编号分配，正是本 Gate 要抓的情况。
            $serializedB = json_encode($resultB, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('concurrent decision', (string) $serializedB, 'worker B 撞到了 UNIQUE race 的受控 422 路径，说明编号没有被行锁串行化。');

            // ── 锁与等待的证明 ─────────────────────────────────────────
            $this->assertTrue(
                $rowLockedDuringPause,
                'A 暂停期间，用第三条连接的 FOR UPDATE NOWAIT 竟然抢到了 content_items 行，说明排他锁不存在。',
            );
            $this->assertNotEmpty($locksHeldByA, 'A 暂停期间 performance_schema.data_locks 里没有任何 GRANTED 锁。');
            $this->assertSame((int) $wait['requesting_connection_id'], $connectionB, '等待记录的 requesting 端必须是 worker B。');
            $this->assertSame((int) $wait['blocking_connection_id'], $connectionA, '等待记录的 blocking 端必须是 worker A。');

            // 证据 3（仅作旁证）：B 的调用早于 A 释放、而拿到锁晚于 A 释放。
            $this->assertNotNull($resumed, 'worker A 未记录恢复时刻。');
            $this->assertLessThan(
                (float) $resumed['resumed_at'],
                (float) $resultB['call_started_at'],
                'worker B 必须在 worker A 恢复之前就已发起调用，否则这不是并发场景。',
            );
            $this->assertGreaterThan(
                (float) $resumed['resumed_at'],
                (float) $resultB['lock_returned_at'],
                'worker B 必须在 worker A 释放之后才拿到行锁——这是它确实被挂起过的时间旁证。',
            );

            // ── 业务结果 ──────────────────────────────────────────────
            $rows = DB::table('duplicate_review_decisions')
                ->where('project_id', $project->id)
                ->where('content_item_id', $item->id)
                ->where('query_page_version_id', $queryVersion->id)
                ->where('query_field', 'page_title')
                ->where('match_page_version_id', $matchVersion->id)
                ->where('match_field', 'page_title')
                ->orderBy('decision_no')
                ->get();

            $this->assertCount(2, $rows, '同一 pair 必须恰好留下 2 条 Decision。');

            $first = $rows->firstWhere('decision_no', 1);
            $second = $rows->firstWhere('decision_no', 2);

            $this->assertNotNull($first, '缺少 decision_no = 1。');
            $this->assertNotNull($second, '缺少 decision_no = 2。');

            $this->assertSame([1, 2], $rows->pluck('decision_no')->map('intval')->all(), 'decision_no 必须严格为 [1, 2]。');
            $this->assertNotSame((int) $first->id, (int) $second->id, '两条 Decision 必须是不同的记录（ID 不同）。');

            // #1 不被覆盖：两次 decision / note 都要原样保留。
            $this->assertSame('confirmed_duplicate', $first->decision, '#1 的 decision 被覆盖了。');
            $this->assertSame(self::NOTE_A, $first->note, '#1 的 note 被覆盖了。');
            $this->assertSame('ignored', $second->decision, '#2 的 decision 不正确。');
            $this->assertSame(self::NOTE_B, $second->note, '#2 的 note 不正确。');

            // latest 必须是 #2。
            $latest = DB::table('duplicate_review_decisions')
                ->where('project_id', $project->id)
                ->where('content_item_id', $item->id)
                ->max('decision_no');

            $this->assertSame(2, (int) $latest, '最新决定必须是 #2。');

            // worker 自报的返回值要和库里的实际记录对得上，防止断言只看 SQL 不看 service。
            $this->assertSame((int) $first->id, (int) $resultA['decision_id'], 'worker A 返回的 ID 与库中 #1 不一致。');
            $this->assertSame((int) $second->id, (int) $resultB['decision_id'], 'worker B 返回的 ID 与库中 #2 不一致。');
        } finally {
            // worker 必须被回收：留着活着的事务会占住行锁，污染后续用例。
            $workerA?->kill();
            $workerB?->kill();
            $this->cleanupScenario((int) $project->id);
            $runtime->destroy();
        }
    }

    /**
     * Gate 自证：本测试只允许跑在约定的隔离 MySQL 测试库上。
     *
     * bootstrap 已经在进程启动前验过一遍；这里再从已建立的连接上确认一次，
     * 防止因为配置被换、或本测试被误注册到其它 suite 而给出假结论。
     */
    private function assertMysql84Target(): void
    {
        $connection = (array) config('database.connections.mysql');

        $this->assertSame('mysql', (string) config('database.default'), '本 Gate 测试必须跑在 MySQL 上。');
        $this->assertSame(self::EXPECTED_DATABASE, (string) ($connection['database'] ?? ''), '本 Gate 测试必须跑在隔离的 MySQL 测试库上。');

        // DB_URL 的判定与 Gate 其余五处完全一致（phpunit.mysql84.xml、bootstrap、
        // 并发 worker、scripts/test-mysql.sh、.env.mysql-testing.example、文档）：
        //   允许 —— 未设置（null）、空字符串，以及 Laravel 归一化后落在这两种形态的值；
        //   拒绝 —— 任何其它非空值，含纯空格与真实 URL。
        //
        // 不能写成 assertNull()：phpunit.mysql84.xml 写的是 <env name="DB_URL" value=""/>，
        // 正确配置下 config 读到的是**空字符串**而不是 null，assertNull() 会把合法配置
        // 判成失败——那是一个假阴性，也和已锁定的契约自相矛盾。
        // 也不用 assertEmpty()/== '' 之类的宽松写法：那会把 '0'、' ' 这类非法值一起放过。
        $actualUrl = $connection['url'] ?? null;
        $this->assertTrue(
            $actualUrl === null || $actualUrl === '',
            'DB_URL 只允许未设置（null）或空字符串。实际值：'.var_export($actualUrl, true)
            .'。任何其它非空值（含纯空格）都会整体覆盖 host/port/database/username/password，'
            .'使逐项校验形同虚设。',
        );

        $this->assertTrue(
            Schema::hasTable('duplicate_review_decisions'),
            'duplicate_review_decisions 表不存在。请先执行 scripts/test-mysql.sh verify 完成 migrate。',
        );
    }

    /**
     * @return array<string, string>
     */
    private function expectedTarget(): array
    {
        $connection = (array) config('database.connections.mysql');

        return [
            'expected_host' => (string) ($connection['host'] ?? ''),
            'expected_port' => (string) ($connection['port'] ?? ''),
            'expected_database' => (string) ($connection['database'] ?? ''),
        ];
    }

    /**
     * 造一个真实的可决策场景：当前篇的 working 版本 vs 历史篇的 formal 版本。
     *
     * 字段取值与 tests/Feature/DuplicateReviewApiTest.php 的 fixture 保持同源——
     * 候选判定依赖 DEV-D08 的 Exact Normalization，只有真正是 candidate 的 pair
     * 才能走到 appendDecision 的 INSERT，否则会被 "This pair is not a duplicate
     * candidate." 提前 422 掉，那样锁根本不会参与。
     *
     * @return array{0: Project, 1: ContentItem, 2: ContentPageVersion, 3: ContentPageVersion}
     */
    private function createScenario(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();

        $topic = Topic::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
        ]);

        $item = ContentItem::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
            'topic_id' => $topic->id,
        ]);

        $historyTopic = Topic::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
        ]);

        $historyItem = ContentItem::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
            'topic_id' => $historyTopic->id,
        ]);

        $revision = ContentCopyRevision::factory()->create([
            'project_id' => $project->id,
            'content_item_id' => $historyItem->id,
            'revision_no' => 1,
        ]);

        $match = $this->version($historyItem, 1, PageType::Content, ['page_title' => self::SHARED_TEXT], $revision);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => self::SHARED_TEXT]);

        return [$project, $item, $query, $match];
    }

    /**
     * @param  array<string, mixed>  $copy
     */
    private function version(
        ContentItem $item,
        int $pageNo,
        PageType $type,
        array $copy,
        ?ContentCopyRevision $revision = null,
        ?ContentPage $page = null,
    ): ContentPageVersion {
        $page ??= ContentPage::factory()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'page_no' => $pageNo,
            'page_type' => $type,
        ]);

        return ContentPageVersion::factory()->create([
            'project_id' => $item->project_id,
            'content_item_id' => $item->id,
            'content_page_id' => $page->id,
            'copy_revision_id' => $revision?->id,
            'version_no' => $page->versions()->count() + 1,
            'page_no_snapshot' => $revision === null ? null : $pageNo,
            'page_type_snapshot' => $revision === null ? null : $type->value,
            'cover_title' => null,
            'cover_subtitle' => null,
            'page_title' => null,
            'page_small_text' => null,
            'closing_line' => null,
            ...$copy,
        ]);
    }

    /**
     * 按外键依赖顺序清理，保证重跑幂等。
     *
     * worker 的写入是真实提交的（这样才能跨会话可见），因此必须显式回收；
     * 否则第二次运行时 #1/#2 已存在，编号会继续往后跳，断言就不再有意义。
     */
    private function cleanupScenario(int $projectId): void
    {
        DB::table('duplicate_review_decisions')->where('project_id', $projectId)->delete();
        DB::table('content_page_versions')->where('project_id', $projectId)->delete();
        DB::table('content_copy_revisions')->where('project_id', $projectId)->delete();
        DB::table('content_pages')->where('project_id', $projectId)->delete();
        DB::table('content_items')->where('project_id', $projectId)->delete();
        DB::table('topics')->where('project_id', $projectId)->delete();
        DB::table('content_columns')->where('project_id', $projectId)->delete();
        DB::table('projects')->whereKey($projectId)->delete();
    }
}
