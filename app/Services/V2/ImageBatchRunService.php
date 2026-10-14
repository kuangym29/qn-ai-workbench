<?php

namespace App\Services\V2;

use App\Enums\ImageBatchRunMode;
use App\Enums\ImageBatchRunStatus;
use App\Models\ContentBranch;
use App\Models\ContentPage;
use App\Models\ImageBatchDraft;
use App\Models\ImageBatchRun;
use App\Models\ImageBatchUnitSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 图片批次执行（第一阶段：只创建批次执行记录）。
 *
 * 明确不做：真实生图 / Images API / ChatGPT Work 调用 / 素材上传 / 复杂 Workflow。
 * 因此 run 创建后状态恒为 queued —— 绝不伪造成 completed / generated。
 *
 * 规则：
 *   full    unit_ids 必须为空或被忽略，后端解析为当前全部有效页面；
 *   trial / partial 必须至少 1 个页面，且必须属于当前批次。
 */
class ImageBatchRunService
{
    public function __construct(
        private readonly ImageBatchWorkspaceService $workspace = new ImageBatchWorkspaceService,
        private readonly ImageBatchAssetResolver $assets = new ImageBatchAssetResolver,
    ) {}

    /**
     * @param  array{mode: string, unit_ids?: array<int, mixed>}  $input
     * @return array{batch_id: int, mode: string, unit_ids: list<int>, status: string}
     *
     * 返回体严格对齐 C05-R1 的 ImageBatchRunResult，不附加任何前端未声明字段。
     */
    public function run(ContentBranch $branch, array $input): array
    {
        $this->workspace->assertImageBatch($branch);

        $mode = ImageBatchRunMode::tryFrom((string) ($input['mode'] ?? ''));
        if ($mode === null) {
            throw new InvalidArgumentException('mode 必须是 full / trial / partial。');
        }

        $item = $branch->contentItem;

        return DB::transaction(function () use ($branch, $item, $mode, $input): array {
            $draft = $this->workspace->ensureDraft($branch);
            $pages = $this->workspace->pages($item);
            $this->workspace->syncSettings($draft, $pages);

            /** @var Collection<int, ImageBatchUnitSetting> $settings */
            $settings = $draft->unitSettings()->get()->keyBy('id');

            $ids = $mode === ImageBatchRunMode::Full
                ? $this->allUnitIds($settings, $pages)
                : $this->resolveUnitIds($settings, $input['unit_ids'] ?? []);

            $selected = $settings->only($ids);
            $selectedPages = $pages->filter(fn (ContentPage $page): bool => $selected->contains(
                fn (ImageBatchUnitSetting $s): bool => (int) $s->content_page_id === (int) $page->id
            ))->values();

            $run = ImageBatchRun::query()->create([
                'image_batch_draft_id' => $draft->id,
                'mode' => $mode,
                'unit_ids' => $ids,
                'status' => ImageBatchRunStatus::Queued,
                'instruction_snapshot' => $this->snapshot($draft, $mode, $selected, $pages),
                'created_at' => now(),
            ]);

            return [
                'batch_id' => $run->id,
                'mode' => $mode->value,
                'unit_ids' => $ids,
                'status' => $run->status->value,
            ];
        });
    }

    /**
     * full：当前全部有效页面（按页码升序）。
     *
     * @param  Collection<int, ImageBatchUnitSetting>  $settings
     * @param  Collection<int, ContentPage>  $pages
     * @return list<int>
     */
    private function allUnitIds(Collection $settings, Collection $pages): array
    {
        $pageOrder = $pages->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $settings
            ->sortBy(fn (ImageBatchUnitSetting $s): int => array_search((int) $s->content_page_id, $pageOrder, true) ?: 0)
            ->map(fn (ImageBatchUnitSetting $s): int => (int) $s->id)
            ->values()
            ->all();
    }

    /**
     * trial / partial：至少 1 个，且必须属于当前批次。
     *
     * @param  Collection<int, ImageBatchUnitSetting>  $settings
     * @param  array<int, mixed>  $unitIds
     * @return list<int>
     */
    private function resolveUnitIds(Collection $settings, array $unitIds): array
    {
        $ids = [];
        foreach ($unitIds as $id) {
            $id = (int) $id;
            if (! $settings->has($id)) {
                throw new InvalidArgumentException('unit_ids 不属于当前图片批次。');
            }
            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            throw new InvalidArgumentException('trial / partial 必须至少选择 1 个页面。');
        }

        return $ids;
    }

    /**
     * 当次任务快照：整批说明 / 备注 / 相关页面当前内容摘要 / 特殊要求 / 资产摘要。
     * 只保存「本批任务」，不存重复 SOP 全文。
     *
     * @param  Collection<int, ImageBatchUnitSetting>  $selected
     * @param  Collection<int, ContentPage>  $pages
     * @return array<string, mixed>
     */
    private function snapshot(
        ImageBatchDraft $draft,
        ImageBatchRunMode $mode,
        Collection $selected,
        Collection $pages,
    ): array {
        $pageMap = $pages->keyBy(fn (ContentPage $page): int => (int) $page->id);
        $pageRows = [];

        foreach ($selected as $setting) {
            /** @var ContentPage|null $page */
            $page = $pageMap->get((int) $setting->content_page_id);
            if ($page === null) {
                continue;
            }

            $pageRows[] = [
                'page_id' => $page->id,
                'page_no' => $page->page_no,
                'page_type' => $page->page_type->value,
                'final_copy' => $page->final_copy,
                'visual_evidence' => $page->visual_evidence,
                'special_requirement' => $setting->special_requirement,
                'status' => $setting->status->value,
            ];
        }

        return [
            'mode' => $mode->value,
            'batch_instruction' => (string) $draft->batch_instruction,
            'batch_note' => $draft->batch_note,
            'pages' => $pageRows,
            'assets' => $this->assets->assets($pages),
            'summary' => $mode->label().'：'.implode('、', $this->pageLabels($selected, $pages)),
        ];
    }

    /**
     * @param  Collection<int, ImageBatchUnitSetting>  $selected
     * @param  Collection<int, ContentPage>  $pages
     * @return list<string>
     */
    private function pageLabels(Collection $selected, Collection $pages): array
    {
        $pageMap = $pages->keyBy(fn (ContentPage $page): int => (int) $page->id);
        $nos = [];

        foreach ($selected as $setting) {
            $page = $pageMap->get((int) $setting->content_page_id);
            if ($page !== null) {
                $nos[] = [(int) $page->page_no, 'P'.$page->page_no];
            }
        }

        usort($nos, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return array_map(fn (array $row): string => $row[1], $nos);
    }
}
