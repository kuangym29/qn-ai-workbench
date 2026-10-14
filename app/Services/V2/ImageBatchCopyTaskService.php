<?php

namespace App\Services\V2;

use App\Enums\AssetCheckStatus;
use App\Enums\AssetCheckType;
use App\Enums\PageType;
use App\Models\ContentBranch;
use App\Models\ImageBatchUnitSetting;

/**
 * 复制整批任务文本：由后端组装，前端不拼 Prompt。
 *
 * 输入来源：最新 batch_instruction / batch_note / 页面结构（final_copy、visual_evidence、
 * 特殊要求）/ 资产状态。只表达本批任务，不复制本地 SOP / 规范全文。
 */
class ImageBatchCopyTaskService
{
    private const PAGE_TYPE_LABELS = [
        PageType::Cover->value => '封面',
        PageType::Content->value => '正文',
        PageType::ColumnClosing->value => '栏目收尾',
        PageType::FixedBackCover->value => '固定封底',
    ];

    public function __construct(
        private readonly ImageBatchWorkspaceService $workspace = new ImageBatchWorkspaceService,
        private readonly ImageBatchAssetResolver $assets = new ImageBatchAssetResolver,
    ) {}

    /** @return array{task: string} */
    public function task(ContentBranch $branch): array
    {
        $this->workspace->assertImageBatch($branch);

        $draft = $this->workspace->ensureDraft($branch);
        $pages = $this->workspace->pages($branch->contentItem);
        $this->workspace->syncSettings($draft, $pages);

        $settings = $draft->unitSettings()->get()->keyBy('content_page_id');
        $lines = [
            '【整批生图说明】',
            trim((string) $draft->batch_instruction) === '' ? '（未填写）' : (string) $draft->batch_instruction,
            '',
            '【整批备注】',
            $draft->batch_note === null || trim((string) $draft->batch_note) === ''
                ? '（无）'
                : (string) $draft->batch_note,
            '',
            '【页面单元】',
        ];

        foreach ($pages as $page) {
            /** @var ImageBatchUnitSetting|null $setting */
            $setting = $settings->get($page->id);
            $typeLabel = self::PAGE_TYPE_LABELS[$page->page_type->value] ?? $page->page_type->value;

            $lines[] = 'P'.$page->page_no.'（'.$typeLabel.'）';
            $lines[] = '  最终文字：'.($page->final_copy ?? '（无）');
            $lines[] = '  画面证据：'.($page->visual_evidence ?? '（无）');
            $lines[] = '  特殊要求：'.($setting?->special_requirement ?? '（无）');
            $lines[] = '  当前状态：'.($setting?->status->label() ?? '待制作');
            $lines[] = '';
        }

        $assets = $this->assets->assets($pages);
        $lines[] = '【资产检查】';
        if ($assets === []) {
            $lines[] = '（暂无资产引用）';
        } else {
            foreach ($assets as $asset) {
                $type = AssetCheckType::tryFrom((string) $asset['type']);
                $status = AssetCheckStatus::tryFrom((string) $asset['status']);
                $lines[] = '  '.($type?->label() ?? $asset['type']).'：'.$asset['name']
                    .'（'.($status?->label() ?? $asset['status']).'）';
            }
        }

        return ['task' => implode("\n", $lines)];
    }
}
