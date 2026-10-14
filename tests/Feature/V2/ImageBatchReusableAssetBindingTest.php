<?php

namespace Tests\Feature\V2;

use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchRun;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use App\Services\V2\EmptyAssetReferenceReader;
use App\Services\V2\ReusableAssetIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * I06B 真实容器链路测试（零 Mock）。
 *
 * 锁定：GET / copy-task / queued run 全部经由容器解析的
 *   ImageBatchController → ImageBatchWorkspaceService → ImageBatchAssetResolver
 *   → ReusableAssetNameSource（绑到 ReusableAssetReferenceReader + ReusableAssetIndexService）
 * 真实串起来，验证：
 *   1. 显式引用（ExplicitTestReferenceReader）经索引解析出 characters / scene / assets；
 *   2. 默认（EmptyAssetReferenceReader）返回空，绝不从正文猜人名 / 场景；
 *   3. copy-task 与 run 快照复用同一个 Resolver；
 *   4. GET / copy-task / queued run 一律不更新 last_used_at；
 *   5. 索引状态按 project 作用域隔离，绝不跨 Project 串数据；
 *   6. 本模块不产出 new_in_batch（那是 ImageBatch 自己的未来会话态）。
 */
class ImageBatchReusableAssetBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 用真实显式读取器替换容器默认（EmptyAssetReferenceReader）。
        $this->app->bind(ReusableAssetReferenceReader::class, ExplicitTestReferenceReader::class);
    }

    private function acting(ContentItem $item): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $item->project_id]);
    }

    private function batchBranch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => Channel::WechatOfficial->value,
            'format' => ContentFormat::ImagePost->value,
        ]);
    }

    private function page(ContentItem $item, int $pageNo, array $extra = []): ContentPage
    {
        return ContentPage::factory()->for($item)->create(array_merge([
            'page_no' => $pageNo,
            'page_type' => 'content',
            'final_copy' => 'fc'.$pageNo,
            'visual_evidence' => 've'.$pageNo,
        ], $extra));
    }

    private function base(ContentItem $item, ContentBranch $branch, string $suffix = ''): string
    {
        return "/api/projects/{$item->project_id}/items/{$item->id}/branches/{$branch->id}/image-batch".$suffix;
    }

    /** @return array<string, mixed> */
    private function show(ContentItem $item, ContentBranch $branch): array
    {
        return $this->getJson($this->base($item, $branch))->assertOk()->json();
    }

    /** 用真实索引服务登记资产状态（不 Mock）。 */
    private function seedIndex(Project $project, array $entries): void
    {
        /** @var ReusableAssetIndexService $index */
        $index = $this->app->make(ReusableAssetIndexService::class);
        foreach ($entries as [$type, $name, $status]) {
            if ($status === ReusableAssetStatus::Existing) {
                $index->markExisting($project, $type, $name);
            } else {
                $index->markMissing($project, $type, $name);
            }
        }
    }

    /** @return string|null */
    private function statusOf(array $body, string $name): ?string
    {
        foreach ($body['assets'] as $asset) {
            if ($asset['name'] === $name) {
                return $asset['status'];
            }
        }

        return null;
    }

    // ── 1. 真实链路：显式引用经索引解析 ──────────────────────────────────────

    public function test_real_http_get_resolves_references_through_full_container_chain(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);

        // 妈妈 / 客厅 已登记（existing），爸爸 / 厨房 缺失（missing）。
        $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing],
        ]);

        $body = $this->show($item, $branch);

        // 每页引用经索引解析。
        $this->assertSame(['妈妈'], $body['units'][0]['characters']);
        $this->assertSame('客厅', $body['units'][0]['scene']);
        $this->assertSame(['爸爸'], $body['units'][1]['characters']);
        $this->assertSame('厨房', $body['units'][1]['scene']);

        // 整批聚合（按页面顺序，保持出现顺序）。
        $this->assertSame([
            ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
            ['type' => 'character', 'name' => '爸爸', 'status' => 'missing'],
            ['type' => 'scene', 'name' => '厨房', 'status' => 'missing'],
        ], $body['assets']);
    }

    // ── 2. 默认空：绝不从正文猜人名 / 场景 ──────────────────────────────────

    public function test_default_empty_without_explicit_references_no_text_inference(): void
    {
        // 即便索引里登记了「妈妈 existing」，只要引用读取器返回空，就绝不出现。
        $this->app->bind(ReusableAssetReferenceReader::class, EmptyAssetReferenceReader::class);

        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1, ['final_copy' => '妈妈在客厅给爸爸做饭', 'visual_evidence' => '厨房场景']);
        $this->acting($item);
        $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
        ]);

        $body = $this->show($item, $branch);

        $this->assertSame([], $body['units'][0]['characters']);
        $this->assertNull($body['units'][0]['scene']);
        $this->assertSame([], $body['assets']);
    }

    // ── 3. copy-task 与 run 快照复用同一个 Resolver ─────────────────────────

    public function test_copy_task_and_run_snapshot_use_same_resolver(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing],
        ]);

        // copy-task 文本里必须出现四个资产名 + 正确状态文案。
        $task = $this->postJson($this->base($item, $branch, '/copy-task'))
            ->assertOk()
            ->json()['task'];

        $this->assertStringContainsString('妈妈', $task);
        $this->assertStringContainsString('客厅', $task);
        $this->assertStringContainsString('爸爸', $task);
        $this->assertStringContainsString('厨房', $task);
        // 状态文案：existing / missing 对应「已有母版 / 缺失」。
        $this->assertStringContainsString('缺失', $task);

        // run 快照里的 assets 与 GET 一致。
        $result = $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])
            ->assertCreated()
            ->json();

        $run = ImageBatchRun::query()->findOrFail($result['batch_id']);
        // 快照从 MySQL JSON 列回读时键顺序会被规范化（与 SQLite/PHP 插入顺序不同），
        // 但 JS 按键名读取、顺序无关；用 assertEquals 放松字典键序、保留列表顺序。
        $this->assertEquals([
            ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
            ['type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
            ['type' => 'character', 'name' => '爸爸', 'status' => 'missing'],
            ['type' => 'scene', 'name' => '厨房', 'status' => 'missing'],
        ], $run->instruction_snapshot['assets']);
    }

    // ── 4. GET / copy-task / queued run 不更新 last_used_at ──────────────────

    public function test_last_used_at_not_updated_by_get_copy_or_queued_run(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing],
        ]);

        $before = ReusableAsset::query()->pluck('last_used_at', 'name')->all();
        $this->assertSame(['妈妈' => null, '客厅' => null, '爸爸' => null, '厨房' => null], $before);

        $this->show($item, $branch);
        $this->postJson($this->base($item, $branch, '/copy-task'))->assertOk();
        $this->postJson($this->base($item, $branch, '/run'), ['mode' => 'full', 'unit_ids' => []])->assertCreated();

        $after = ReusableAsset::query()->pluck('last_used_at', 'name')->all();
        $this->assertSame(['妈妈' => null, '客厅' => null, '爸爸' => null, '厨房' => null], $after,
            'GET / copy-task / queued run 都不得更新 last_used_at');
    }

    // ── 5. 索引状态按 Project 作用域隔离 ────────────────────────────────────

    public function test_references_are_scoped_to_page_project(): void
    {
        $projectA = Project::factory()->create();
        $itemA = ContentItem::factory()->for($projectA)->create();
        $branchA = $this->batchBranch($itemA);
        $this->page($itemA, 1);

        $projectB = Project::factory()->create();
        $itemB = ContentItem::factory()->for($projectB)->create();
        $branchB = $this->batchBranch($itemB);
        $this->page($itemB, 1);

        // 只在 A 登记妈妈 / 客厅 为 existing。
        $this->seedIndex($projectA, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
        ]);

        // A：命中索引 → existing。
        $this->acting($itemA);
        $bodyA = $this->show($itemA, $branchA);
        $this->assertSame(['妈妈'], $bodyA['units'][0]['characters']);
        $this->assertSame('客厅', $bodyA['units'][0]['scene']);
        $this->assertSame('existing', $this->statusOf($bodyA, '妈妈'));

        // B：索引无条目 → missing（绝不串到 A 的数据）。
        $this->acting($itemB);
        $bodyB = $this->show($itemB, $branchB);
        $this->assertSame(['妈妈'], $bodyB['units'][0]['characters']);
        $this->assertSame('客厅', $bodyB['units'][0]['scene']);
        $this->assertSame('missing', $this->statusOf($bodyB, '妈妈'));
        $this->assertSame('missing', $this->statusOf($bodyB, '客厅'));
    }

    // ── 6. 本模块绝不产出 new_in_batch ─────────────────────────────────────

    public function test_resolver_never_emits_new_in_batch_status(): void
    {
        $item = ContentItem::factory()->create();
        $branch = $this->batchBranch($item);
        $this->page($item, 1);
        $this->page($item, 2);
        $this->acting($item);
        $this->seedIndex($item->project, [
            [ReusableAssetType::Character, '妈妈', ReusableAssetStatus::Existing],
            [ReusableAssetType::Scene, '客厅', ReusableAssetStatus::Existing],
            [ReusableAssetType::Character, '爸爸', ReusableAssetStatus::Missing],
            [ReusableAssetType::Scene, '厨房', ReusableAssetStatus::Missing],
        ]);

        $body = $this->show($item, $branch);

        foreach ($body['assets'] as $asset) {
            $this->assertContains($asset['status'], ['existing', 'missing'],
                'ReusableAssetNameSource 不得产出 new_in_batch');
        }
    }
}

/**
 * 测试用显式引用读取器：P1 = 妈妈 / 客厅，P2 = 爸爸 / 厨房，其余页无引用。
 * 它不依赖任何 Project（纯测试桩），索引作用域由 ReusableAssetNameSource 负责。
 */
class ExplicitTestReferenceReader implements ReusableAssetReferenceReader
{
    public function names(ContentPage $page): array
    {
        return match ((int) $page->page_no) {
            1 => [
                ['type' => 'character', 'name' => '妈妈'],
                ['type' => 'scene', 'name' => '客厅'],
            ],
            2 => [
                ['type' => 'character', 'name' => '爸爸'],
                ['type' => 'scene', 'name' => '厨房'],
            ],
            default => [],
        };
    }
}
