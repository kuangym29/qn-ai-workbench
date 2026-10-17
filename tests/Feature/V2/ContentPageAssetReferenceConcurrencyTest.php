<?php

namespace Tests\Feature\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageReusableAssetReference;
use App\Models\ReusableAsset;
use App\Services\V2\ContentPageAssetReferenceService;
use App\Services\V2\ReusableAssetIndexService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Gate\Support\ConcurrencyRuntimeDirectory;
use Tests\Gate\Support\LockWaitObserver;
use Tests\TestCase;

/**
 * P5-I03-R1：ContentPage 显式资产引用的**并发替换**回归（真实 MySQL 8.4 + InnoDB）。
 *
 * 背景：R1 修正了 ContentPageAssetReferenceService::replace() 的事务边界 ——
 * 原先 SELECT ... FOR UPDATE 在 DB::transaction() 之外，自动提交模式下锁拿到即释放，
 * 并发 replace 会交错成「半套引用 / 混合人物场景 / 重复行」。本类锁定修正后的行为：
 *
 *   1. 同一 ContentPage 两个并发 replace 必须被行锁串行化：后到者整体取代前者，
 *      最终关系必须是一次完整 replace 的结果（完整 A 或完整 B），
 *      绝不混合（妈妈+公园 / 爸爸+客厅）、绝不两个场景、绝不重复行；
 *   2. 串行化必须由 MySQL 侧证据证明（performance_schema.data_lock_waits 出现
 *      requesting=B / blocking=A），不是靠 sleep 推断；
 *   3. 不得出现 deadlock 泄漏成 500。
 *
 * 为什么只在 MySQL 上跑：SQLite 的 lockForUpdate 编译为空串（无行锁语义），
 * 且整库写锁会把这里的结论全部掩盖，因此 SQLite 下直接 skip。
 *
 * 风格对齐 tests/Feature/V2/ReusableAssetConcurrencyTest.php（Feature 侧 worker）
 * 与 tests/Gate/Mysql84ConcurrencyTest.php（锁等待观测）。
 */
class ContentPageAssetReferenceConcurrencyTest extends TestCase
{
    use DatabaseMigrations {
        runDatabaseMigrations as private migrateReferenceConcurrencyDatabase;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent replace regression requires MySQL.');
        }

        $this->assertTrue(app()->environment('testing'), 'Requires the isolated testing environment.');
        $this->assertTrue(str_ends_with((string) DB::connection()->getDatabaseName(), '_test'), 'Requires a dedicated test database.');
        $this->assertTrue((string) DB::connection()->getConfig('port') !== '3399' || getenv('GITHUB_ACTIONS') === 'true', 'Existing local database is forbidden.');
        $this->migrateReferenceConcurrencyDatabase();
    }

    /**
     * 主场景：A 持锁暂停 → B 撞上同一把行锁 → 释放 A → B 完整取代 A。
     *
     * 这是 R1 要钉住的核心不变量：replace 的「事务 begin → SELECT FOR UPDATE →
     * 校验 → delete → insert → commit」必须整体串行化。
     */
    public function test_concurrent_replace_serializes_under_a_real_row_lock(): void
    {
        // 观测能力预检：读不到 performance_schema 的锁表就不是"观测失败"，
        // 而是没有证据，此时必须 FAIL，不允许退化成顺序执行。
        $observer = new LockWaitObserver;
        $observer->assertObservable();

        $scenario = $this->scenario();
        $page = $scenario['page'];
        $assets = $scenario['assets'];

        // 起始绑定 姐姐 + 玄关：让 A 的 replace 必须真的先删后插，
        // B 也必须删掉 A 提交后的行，从而完整走一遍取代链。
        app(ContentPageAssetReferenceService::class)->replace($page, [$assets['姐姐']->id], $assets['玄关']->id);

        $runtime = ConcurrencyRuntimeDirectory::create('qn-page-ref-replace');
        $workerA = null;
        $workerB = null;

        try {
            $workerA = $this->startWorker($runtime, 'A', [
                'page_id' => $page->id,
                'character_asset_ids' => [$assets['妈妈']->id],
                'scene_asset_id' => $assets['客厅']->id,
                'mode' => 'orchestrated',
            ]);

            $paused = $runtime->awaitJson('paused.json', 25.0);
            $this->assertNotNull($paused, '[concurrency] worker A 未进入持锁暂停，并发窗口没有形成。');

            $connectionA = (int) ($runtime->readJson('conn-A.json')['conn_id'] ?? 0);
            $this->assertGreaterThan(0, $connectionA, 'worker A 未报告 CONNECTION_ID()，无法定位锁关系。');

            // 证据 2：此刻 content_pages 这一行确实被排他锁定（第三条连接 NOWAIT 探针）。
            $this->assertTrue(
                $observer->rowIsLockedForUpdate('content_pages', $page->id),
                'A 暂停期间，用第三条连接的 FOR UPDATE NOWAIT 竟然抢到了 content_pages 行，'
                .'说明 SELECT ... FOR UPDATE 没有落在事务内（R1 修正的行为被回退）。',
            );
            $this->assertNotEmpty($observer->grantedLocksHeldBy($connectionA), 'A 暂停期间 performance_schema.data_locks 里没有任何 GRANTED 锁。');

            $workerB = $this->startWorker($runtime, 'B', [
                'page_id' => $page->id,
                'character_asset_ids' => [$assets['爸爸']->id],
                'scene_asset_id' => $assets['公园']->id,
                'mode' => 'orchestrated',
            ]);

            $connB = $runtime->awaitJson('conn-B.json', 15.0);
            $this->assertNotNull($connB, '[concurrency] worker B 未能在 15s 内建立连接。');

            $connectionB = (int) $connB['conn_id'];
            $this->assertNotSame($connectionA, $connectionB, '两个 worker 必须是两个 MySQL 会话；CONNECTION_ID 相同说明进程隔离没生效。');

            // 证据 1（决定性）：data_lock_waits 里必须出现 requesting=B、blocking=A。
            $wait = $observer->waitForRowLockWait($connectionB, $connectionA, 8.0);
            $this->assertNotNull(
                $wait,
                "[concurrency] 未观测到 worker B 等待 worker A 的行锁，本 Gate **没有通过**。\n"
                .'  服务器现场：'.json_encode($observer->snapshot($connectionB, $connectionA), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            );
            $this->assertSame($connectionB, (int) $wait['requesting_connection_id'], '等待记录的 requesting 端必须是 worker B。');
            $this->assertSame($connectionA, (int) $wait['blocking_connection_id'], '等待记录的 blocking 端必须是 worker A。');

            $runtime->release();

            $this->assertTrue($this->waitForExit($workerA, 30.0), 'worker A 未在 30s 内退出。');
            $this->assertTrue($this->waitForExit($workerB, 30.0), 'worker B 未在 30s 内退出。');

            $resultA = $runtime->readJson('result-A.json');
            $resultB = $runtime->readJson('result-B.json');
            $resumed = $runtime->readJson('released.json');

            $this->assertNotNull($resultA, 'worker A 未产出结果文件。');
            $this->assertNotNull($resultB, 'worker B 未产出结果文件。');
            $this->assertNotNull($resumed, 'worker A 未记录恢复时刻。');

            $this->assertSame(0, $workerA->getExitCode(), 'worker A 未正常退出（不得把异常泄漏成非 0）。');
            $this->assertSame(0, $workerB->getExitCode(), 'worker B 未正常退出（不得把异常泄漏成非 0）。');
            $this->assertSame('ok', $resultA['status'] ?? null, 'worker A 的 replace 失败：'.json_encode($resultA, JSON_UNESCAPED_UNICODE));
            $this->assertSame('ok', $resultB['status'] ?? null, 'worker B 的 replace 失败：'.json_encode($resultB, JSON_UNESCAPED_UNICODE));

            // 证据 3（时间旁证）：B 的调用早于 A 释放、拿到锁晚于 A 释放。
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

            // ── 业务结果：B 完整取代 A ──────────────────────────────
            $this->assertCompleteReplace($page, $assets['爸爸'], $assets['公园']);
            $this->assertSame([$assets['爸爸']->id], $resultB['result']['character_asset_ids'], 'worker B 的返回值与库中最终关系不一致。');
            $this->assertSame($assets['公园']->id, $resultB['result']['scene_asset_id'], 'worker B 的返回值与库中最终关系不一致。');
        } finally {
            // worker 必须被回收：留着活着的事务会占住行锁，污染后续用例。
            $workerA?->stop();
            $workerB?->stop();
            $runtime->destroy();
        }
    }

    /**
     * 同时起跑：两个 worker 齐步 replace 同一个空页面。
     *
     * 允许的终态只有两种——完整 A（妈妈 + 客厅）或完整 B（爸爸 + 公园）。
     * 空页面是关键：起始无引用时，若锁不在事务内，两次 replace 的 DELETE
     * 都锁不到任何行，两个 INSERT 集就会同时落地，形成混合态 / 两个场景。
     */
    public function test_simultaneous_replace_never_mixes(): void
    {
        $scenario = $this->scenario();
        $page = $scenario['page'];
        $assets = $scenario['assets'];

        $runtime = ConcurrencyRuntimeDirectory::create('qn-page-ref-race');
        $workers = [];

        try {
            $workers['A'] = $this->startWorker($runtime, 'A', [
                'page_id' => $page->id,
                'character_asset_ids' => [$assets['妈妈']->id],
                'scene_asset_id' => $assets['客厅']->id,
                'mode' => 'race',
            ]);
            $workers['B'] = $this->startWorker($runtime, 'B', [
                'page_id' => $page->id,
                'character_asset_ids' => [$assets['爸爸']->id],
                'scene_asset_id' => $assets['公园']->id,
                'mode' => 'race',
            ]);

            // 两个 worker 都必须先连上（证明是两个独立会话），再齐步放行。
            $this->assertNotNull($runtime->awaitJson('conn-A.json', 25.0), 'worker A 未能在 25s 内建立连接。');
            $this->assertNotNull($runtime->awaitJson('conn-B.json', 25.0), 'worker B 未能在 25s 内建立连接。');

            $runtime->putJson('go', ['released_at' => microtime(true)]);

            foreach (['A', 'B'] as $role) {
                $this->assertTrue($this->waitForExit($workers[$role], 40.0), "worker {$role} 未在 40s 内退出。");
            }

            foreach (['A', 'B'] as $role) {
                $result = $runtime->readJson("result-{$role}.json");
                $this->assertNotNull($result, "worker {$role} 未产出结果文件。");
                $this->assertSame('ok', $result['status'] ?? null, "worker {$role} 必须干净完成（不得 deadlock 泄漏成 500）：".json_encode($result, JSON_UNESCAPED_UNICODE));
                $this->assertSame(0, $workers[$role]->getExitCode(), "worker {$role} 未正常退出。");
            }

            $winner = $this->assertFinalIsCompleteReplaceOfEither(
                $page,
                ['character' => $assets['妈妈'], 'scene' => $assets['客厅']],
                ['character' => $assets['爸爸'], 'scene' => $assets['公园']],
            );
            $this->assertContains($winner, ['A', 'B']);
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
            }
            $runtime->destroy();
        }
    }

    // ── fixture ───────────────────────────────────────────────────────

    /**
     * 一个真实项目：item + image_post 分支 + 1 个内容页，以及人物 / 场景资产索引。
     *
     * @return array{project: \App\Models\Project, item: ContentItem, branch: ContentBranch, page: ContentPage, assets: array<string, ReusableAsset>}
     */
    private function scenario(): array
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);
        $page = ContentPage::factory()->for($item)->create([
            'page_no' => 1,
            'page_type' => 'content',
            'final_copy' => 'fc1',
            'visual_evidence' => 've1',
        ]);

        /** @var ReusableAssetIndexService $index */
        $index = $this->app->make(ReusableAssetIndexService::class);
        $assets = [];
        foreach ([
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Character, '姐姐', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '公园', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '玄关', ReusableAssetStatus::Existing],
        ] as [$type, $name, $status]) {
            $assets[$name] = $status === ReusableAssetStatus::Existing
                ? $index->markExisting($item->project, $type, $name)
                : $index->markMissing($item->project, $type, $name);
        }

        return ['project' => $item->project, 'item' => $item, 'branch' => $branch, 'page' => $page, 'assets' => $assets];
    }

    // ── worker 编排 ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $job
     */
    private function startWorker(ConcurrencyRuntimeDirectory $runtime, string $role, array $job): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Fixtures/content_page_reference_concurrency_worker.php')], base_path());
        $process->setTimeout(90);
        // 连接配置经 stdin 传递：凭据绝不进命令行，也不进失败输出。
        $process->setInput(json_encode([
            'connection' => DB::connection()->getConfig(),
            'expected' => $this->expectedTarget(),
            'runtime_dir' => $runtime->path,
            'role' => $role,
            'page_id' => $job['page_id'],
            'character_asset_ids' => $job['character_asset_ids'],
            'scene_asset_id' => $job['scene_asset_id'],
            'mode' => $job['mode'],
            'pause_timeout_seconds' => 30.0,
        ], JSON_THROW_ON_ERROR));
        $process->start();

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private function expectedTarget(): array
    {
        $connection = (array) DB::connection()->getConfig();

        return [
            'expected_host' => (string) ($connection['host'] ?? ''),
            'expected_port' => (string) ($connection['port'] ?? ''),
            'expected_database' => (string) ($connection['database'] ?? ''),
        ];
    }

    private function waitForExit(Process $process, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while ($process->isRunning()) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(50_000);
        }

        return true;
    }

    // ── 终态断言 ──────────────────────────────────────────────────────

    /**
     * 终态必须恰好是一次完整 replace：1 人物 + 1 场景，且就是给定的那一对。
     */
    private function assertCompleteReplace(ContentPage $page, ReusableAsset $character, ReusableAsset $scene): void
    {
        $rows = ContentPageReusableAssetReference::query()
            ->where('content_page_id', $page->id)
            ->orderBy('reusable_asset_id')
            ->get();

        $this->assertCount(2, $rows, '一次完整 replace 必须恰好留下 2 行（1 人物 + 1 场景），实际：'.json_encode($rows->pluck('reusable_asset_id')->all()));

        $ids = $rows->pluck('reusable_asset_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = [$character->id, $scene->id];
        sort($expected);

        $this->assertSame($expected, $ids, '最终关系必须是一次完整 replace 的结果，不得混合 / 残留。');
        $this->assertSame(2, $rows->pluck('reusable_asset_id')->unique()->count(), '不得出现重复引用行。');

        $types = ReusableAsset::query()->whereIn('id', $ids)->get()->keyBy('id');
        $sceneCount = $types->filter(static fn (ReusableAsset $asset): bool => $asset->type === ReusableAssetType::Scene)->count();
        $this->assertSame(1, $sceneCount, '不得出现两个场景。');
    }

    /**
     * 终态必须是完整 A 或完整 B 之一（同时起跑时谁赢不确定，但不允许混合）。
     *
     * @param  array{character: ReusableAsset, scene: ReusableAsset}  $a
     * @param  array{character: ReusableAsset, scene: ReusableAsset}  $b
     * @return 'A'|'B'
     */
    private function assertFinalIsCompleteReplaceOfEither(ContentPage $page, array $a, array $b): string
    {
        $rows = ContentPageReusableAssetReference::query()
            ->where('content_page_id', $page->id)
            ->get();

        $ids = $rows->pluck('reusable_asset_id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        $setA = [$a['character']->id, $a['scene']->id];
        $setB = [$b['character']->id, $b['scene']->id];
        sort($setA);
        sort($setB);

        $isA = $ids === $setA;
        $isB = $ids === $setB;

        $this->assertTrue(
            $isA || $isB,
            '最终关系必须是一次完整 replace 的结果（完整 A 或完整 B），不得混合 / 半套 / 两个场景。'
            .'  实际：'.json_encode($ids).'；A='.json_encode($setA).'；B='.json_encode($setB),
        );
        $this->assertSame(2, $rows->pluck('reusable_asset_id')->unique()->count(), '不得出现重复引用行。');

        return $isA ? 'A' : 'B';
    }
}
