<?php

namespace App\Services\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ImageBatchDraft;
use App\Models\ImageBatchRun;
use App\Models\ImageBatchUnitSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 图片批次工作台：GET 组装 / PUT 草稿保存。
 *
 * 关键不变量：
 *   - 只有 format = image_post 且 channel = wechat_official 的分支可用，其它一律拒绝（422）。
 *   - units 全部来自 ContentPage，页面原文实时读取，不复制进表。
 *   - selected 不属于后端：既不在表里，也不出现在任何响应中。
 *   - PUT 白名单只有 batch_instruction / batch_note / units[].special_requirement；
 *     其余字段（final_copy / visual_evidence / characters / scene / page_type /
 *     status / source_changed / history …）一律由系统计算，写入即拒绝。
 *   - 用户只需维护「这一批怎么做」，页面结构由系统给出。
 */
class ImageBatchWorkspaceService
{
    public function __construct(
        private readonly ImageBatchSourceService $source,
        private readonly ImageBatchAssetResolver $assets,
    ) {}

    /**
     * 组装工作台（未保存过也能读，返回空说明 + 系统给出的页面单元）。
     *
     * @return array<string, mixed>
     */
    public function workspace(ContentBranch $branch): array
    {
        $this->assertImageBatch($branch);
        $item = $branch->contentItem;

        return DB::transaction(function () use ($branch, $item): array {
            $draft = $this->ensureDraft($branch);
            $pages = $this->pages($item);
            $this->syncSettings($draft, $pages);

            $settings = $draft->unitSettings()->get()->keyBy('content_page_id');

            $units = $pages->map(function (ContentPage $page) use ($settings): array {
                /** @var ImageBatchUnitSetting $setting */
                $setting = $settings->get($page->id);

                return [
                    'id' => $setting->id,
                    'page_id' => $page->id,
                    'page_no' => $page->page_no,
                    'page_type' => $page->page_type->value,
                    'final_copy' => $page->final_copy,
                    'visual_evidence' => $page->visual_evidence,
                    'characters' => $this->assets->characters($page),
                    'scene' => $this->assets->scene($page),
                    'special_requirement' => $setting->special_requirement,
                    'status' => $setting->status->value,
                    'source_changed' => $this->source->sourceChanged($setting, $page),
                    'source_change_summary' => $this->source->changeSummary($setting, $page),
                ];
            })->values()->all();

            return [
                'content_title' => (string) $item->title,
                'source_label' => $this->sourceLabel($item),
                'batch_instruction' => (string) $draft->batch_instruction,
                'batch_note' => $draft->batch_note,
                'assets' => $this->assets->assets($pages),
                'units' => $units,
                'history' => $this->history($draft, $settings),
            ];
        });
    }

    /**
     * 保存整批草稿（白名单）。
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(ContentBranch $branch, array $data): ImageBatchDraft
    {
        $this->assertImageBatch($branch);
        $this->assertWhitelist($data);

        return DB::transaction(function () use ($branch, $data): ImageBatchDraft {
            $draft = $this->ensureDraft($branch);

            $draft->batch_instruction = (string) ($data['batch_instruction'] ?? $draft->batch_instruction);
            $draft->batch_note = array_key_exists('batch_note', $data)
                ? ($data['batch_note'] === null ? null : (string) $data['batch_note'])
                : $draft->batch_note;
            $draft->save();

            foreach ($data['units'] ?? [] as $patch) {
                $setting = $draft->unitSettings()
                    ->where('id', (int) ($patch['id'] ?? 0))
                    ->first();

                if ($setting === null) {
                    throw new InvalidArgumentException('units[].id 不属于当前图片批次。');
                }

                $requirement = $patch['special_requirement'] ?? null;
                $setting->special_requirement = $requirement === null || $requirement === ''
                    ? null
                    : (string) $requirement;
                $setting->save();
            }

            return $draft->refresh();
        });
    }

    /** 当前分支的草稿（不存在则建立空草稿）。 */
    public function ensureDraft(ContentBranch $branch): ImageBatchDraft
    {
        $draft = ImageBatchDraft::query()
            ->where('content_branch_id', $branch->id)
            ->first();

        if ($draft !== null) {
            return $draft;
        }

        return ImageBatchDraft::query()->create([
            'project_id' => $branch->project_id,
            'content_item_id' => $branch->content_item_id,
            'content_branch_id' => $branch->id,
            'batch_instruction' => '',
            'batch_note' => null,
        ]);
    }

    /**
     * 当前全部页面（按页码升序）。
     *
     * @return Collection<int, ContentPage>
     */
    public function pages(ContentItem $item): Collection
    {
        return $item->contentPages()->orderBy('page_no')->orderBy('id')->get();
    }

    /**
     * 轻量历史批次（倒序）。
     *
     * @param  Collection<int, ImageBatchUnitSetting>  $settings
     * @return list<array<string, mixed>>
     */
    public function history(ImageBatchDraft $draft, Collection $settings): array
    {
        $pageNoById = [];
        foreach ($settings as $setting) {
            $pageNoById[(int) $setting->id] = (int) ($setting->contentPage?->page_no ?? 0);
        }

        return $draft->runs()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ImageBatchRun $run) use ($pageNoById): array {
                $labels = [];
                foreach ((array) $run->unit_ids as $id) {
                    $no = $pageNoById[(int) $id] ?? null;
                    if ($no !== null && $no > 0) {
                        $labels[] = 'P'.$no;
                    }
                }

                return [
                    'id' => $run->id,
                    'created_at' => $run->created_at?->toJSON(),
                    'page_labels' => $labels,
                    'status' => $run->status->value,
                    'instruction_summary' => $this->summaryOf($run),
                ];
            })
            ->values()
            ->all();
    }

    /** 批次说明摘要（供历史列表轻量展示）。 */
    public function summaryOf(ImageBatchRun $run): ?string
    {
        $snapshot = (array) $run->instruction_snapshot;
        $instruction = trim((string) ($snapshot['batch_instruction'] ?? ''));

        if ($instruction !== '') {
            return Str::limit($instruction, 120, '…');
        }

        return (string) ($snapshot['summary'] ?? null) ?: null;
    }

    /**
     * 为当前页面补齐单元设置（幂等，只补不删、不覆盖已有设置）。
     *
     * @param  Collection<int, ContentPage>  $pages
     */
    public function syncSettings(ImageBatchDraft $draft, Collection $pages): void
    {
        $existing = $draft->unitSettings()->pluck('id', 'content_page_id')->all();

        foreach ($pages as $page) {
            if (isset($existing[$page->id])) {
                continue;
            }

            ImageBatchUnitSetting::query()->create([
                'image_batch_draft_id' => $draft->id,
                'content_page_id' => $page->id,
                'special_requirement' => null,
                'source_fingerprint' => $this->source->fingerprint($page),
                'status' => 'pending',
            ]);
        }
    }

    /**
     * PUT 白名单校验：出现任何非白名单字段即拒绝。
     *
     * @param  array<string, mixed>  $data
     */
    private function assertWhitelist(array $data): void
    {
        $allowed = ['batch_instruction', 'batch_note', 'units'];
        $unknown = array_values(array_diff(array_keys($data), $allowed));

        if ($unknown !== []) {
            throw new InvalidArgumentException('禁止提交字段：'.implode(', ', $unknown).'。');
        }

        foreach ($data['units'] ?? [] as $patch) {
            $unitUnknown = array_values(array_diff(array_keys((array) $patch), ['id', 'special_requirement']));
            if ($unitUnknown !== []) {
                throw new InvalidArgumentException('禁止提交字段：units[].'.implode(', ', $unitUnknown).'。');
            }
        }
    }

    private function sourceLabel(ContentItem $item): string
    {
        $column = $item->contentColumn?->name;

        return $column ? $column.' › '.((string) $item->title) : (string) $item->title;
    }

    /** 分支资格校验：仅 image_post + wechat_official 支持图片批次。 */
    public function assertImageBatch(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::ImagePost) {
            throw new InvalidArgumentException('仅 image_post 分支支持图片批次接口。');
        }
        if ($branch->channel !== Channel::WechatOfficial) {
            throw new InvalidArgumentException('仅 wechat_official 分支支持图片批次接口。');
        }
    }
}
