<?php

namespace Tests\Unit\V2;

use App\Contracts\V2\AssetNameSource;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchRun;
use App\Models\ImageBatchUnitSetting;
use App\Services\V2\ImageBatchAssetResolver;
use App\Services\V2\ImageBatchCopyTaskService;
use App\Services\V2\ImageBatchRunService;
use App\Services\V2\ImageBatchSourceService;
use App\Services\V2\ImageBatchWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 图片批次服务层：来源指纹 / 资产解析 / 执行 / 复制任务 / 白名单。
 */
class ImageBatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private function item(): ContentItem
    {
        return ContentItem::factory()->create();
    }

    private function branch(ContentItem $item): ContentBranch
    {
        return ContentBranch::factory()->for($item)->create([
            'channel' => 'wechat_official',
            'format' => ContentFormat::ImagePost,
        ]);
    }

    private function page(ContentItem $item, int $pageNo, array $extra = []): ContentPage
    {
        return ContentPage::factory()->for($item)->create(array_merge([
            'page_no' => $pageNo,
            'page_type' => 'content',
            'final_copy' => 'fc',
            'visual_evidence' => 've',
        ], $extra));
    }

    // ---------- 来源指纹 ----------

    public function test_fingerprint_changes_when_page_content_changes(): void
    {
        $item = $this->item();
        $page = $this->page($item, 1);
        $service = new ImageBatchSourceService;

        $before = $service->fingerprint($page);
        $page->final_copy = 'changed';
        $after = $service->fingerprint($page);

        $this->assertNotSame($before, $after);
        $this->assertSame(64, strlen($before));
    }

    public function test_source_changed_is_false_when_fingerprint_missing(): void
    {
        $item = $this->item();
        $page = $this->page($item, 1);
        $setting = new ImageBatchUnitSetting(['source_fingerprint' => null]);

        $this->assertFalse((new ImageBatchSourceService)->sourceChanged($setting, $page));
    }

    // ---------- 资产解析 ----------

    public function test_default_asset_name_source_is_empty(): void
    {
        $item = $this->item();
        $page = $this->page($item, 1);
        // I06B：容器默认把 AssetNameSource 绑到 ReusableAssetNameSource（经 EmptyAssetReferenceReader），
        // 无显式引用 → 返回空。这里用真实默认绑定验证「默认空」语义。
        $resolver = new ImageBatchAssetResolver($this->app->make(AssetNameSource::class));

        $this->assertSame([], $resolver->characters($page));
        $this->assertNull($resolver->scene($page));
        $this->assertSame([], $resolver->assets(collect([$page])));
    }

    public function test_asset_resolver_aggregates_dedupes_and_prefers_strongest_status(): void
    {
        $source = new class implements AssetNameSource
        {
            public function references(ContentPage $page): array
            {
                if ((int) $page->page_no === 1) {
                    return [
                        ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'],
                        ['type' => 'scene', 'name' => '客厅', 'status' => 'new_in_batch'],
                    ];
                }

                return [
                    ['type' => 'character', 'name' => '妈妈', 'status' => 'missing'],
                    ['type' => 'character', 'name' => '姐姐', 'status' => 'missing'],
                    ['type' => 'scene', 'name' => '客厅', 'status' => 'existing'],
                    ['type' => 'scene', 'name' => '  ', 'status' => 'existing'],
                    ['type' => 'unknown', 'name' => '忽略', 'status' => 'existing'],
                ];
            }
        };

        $item = $this->item();
        $p1 = $this->page($item, 1);
        $p2 = $this->page($item, 2);
        $resolver = new ImageBatchAssetResolver($source);

        $this->assertSame(['妈妈'], $resolver->characters($p1));
        $this->assertSame('客厅', $resolver->scene($p1));

        $assets = $resolver->assets(collect([$p1, $p2]));

        $this->assertSame([
            ['type' => 'character', 'name' => '妈妈', 'status' => 'missing'],
            ['type' => 'scene', 'name' => '客厅', 'status' => 'new_in_batch'],
            ['type' => 'character', 'name' => '姐姐', 'status' => 'missing'],
        ], $assets);
    }

    // ---------- 工作台 ----------

    public function test_workspace_uses_service_and_provides_unit_ids(): void
    {
        $item = $this->item();
        $branch = $this->branch($item);
        $this->page($item, 1);
        $this->page($item, 2);

        $workspace = $this->app->make(ImageBatchWorkspaceService::class)->workspace($branch);

        $this->assertSame(['content_title', 'source_label', 'batch_instruction', 'batch_note', 'assets', 'units', 'history'], array_keys($workspace));
        $this->assertCount(2, $workspace['units']);
        $this->assertIsInt($workspace['units'][0]['id']);
        $this->assertIsInt($workspace['units'][0]['page_id']);
    }

    public function test_workspace_rejects_non_image_batch_branch(): void
    {
        $item = $this->item();
        $branch = ContentBranch::factory()->for($item)->create([
            'channel' => 'wechat_channels',
            'format' => ContentFormat::Video,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ImageBatchWorkspaceService::class)->workspace($branch);
    }

    public function test_save_draft_rejects_forbidden_top_level_keys(): void
    {
        $item = $this->item();
        $branch = $this->branch($item);

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ImageBatchWorkspaceService::class)->saveDraft($branch, ['batch_instruction' => 'x', 'history' => []]);
    }

    public function test_run_returns_queued_and_persists_snapshot(): void
    {
        $item = $this->item();
        $branch = $this->branch($item);
        $this->page($item, 1);

        $result = $this->app->make(ImageBatchRunService::class)->run($branch, ['mode' => 'full', 'unit_ids' => []]);

        $this->assertSame(['batch_id', 'mode', 'unit_ids', 'status'], array_keys($result));
        $this->assertSame('queued', $result['status']);
        $this->assertSame('full', $result['mode']);

        $run = ImageBatchRun::query()->findOrFail($result['batch_id']);
        $this->assertSame('queued', $run->status->value);
        $this->assertIsArray($run->instruction_snapshot);
    }

    public function test_copy_task_is_assembled_from_draft_and_pages(): void
    {
        $item = $this->item();
        $branch = $this->branch($item);
        $this->page($item, 1, ['final_copy' => '唯一文字']);

        $this->app->make(ImageBatchWorkspaceService::class)->saveDraft($branch, [
            'batch_instruction' => '服务层说明',
            'batch_note' => '服务层备注',
        ]);

        $task = $this->app->make(ImageBatchCopyTaskService::class)->task($branch)['task'];

        $this->assertStringContainsString('服务层说明', $task);
        $this->assertStringContainsString('服务层备注', $task);
        $this->assertStringContainsString('唯一文字', $task);
    }
}
