<?php

namespace App\Services\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewTargetType;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ImageBatchDraft;
use App\Models\Project;
use App\Models\VideoContent;

/**
 * 唯一的正式版本身份计算实现。
 *
 * ReviewSubmissionService（提交审核）与 PublicationService（标记发布）共用本实现，
 * 确保"审核认为是版本 A、发布认为是版本 B"这类架构割裂不可能发生。
 *
 * 职责仅限：
 *   1. 校验 Project / Item / Branch / Target 的作用域与类型匹配；
 *   2. 从服务器已保存正式数据构造 canonical snapshot；
 *   3. 返回稳定 version_label（sha256 前 16 位，前缀"内容版本 "）。
 *
 * 任何需要"当前内容版本身份"的地方都必须走这里，不得复制第二套算法。
 */
class ContentVersionResolver
{
    public function __construct(
        private readonly VideoSourceService $videoSource,
        private readonly ImageBatchAssetResolver $imageBatchAssets,
    ) {}

    /**
     * 校验作用域与目标类型，返回 [content, branch, type]。
     *
     * @return array{0: ContentItem, 1: ContentBranch, 2: ReviewTargetType}
     */
    public function resolve(Project $project, int $item, int $branch, string $target): array
    {
        $content = $project->contentItems()->findOrFail($item);
        $model = $content->contentBranches()->where('project_id', $project->id)->findOrFail($branch);
        $type = ReviewTargetType::tryFrom($target);
        $valid = match ($type) {
            ReviewTargetType::ImagePostCopy, ReviewTargetType::ImageBatch => $model->format === ContentFormat::ImagePost && $model->channel === Channel::WechatOfficial,
            ReviewTargetType::ArticleCopy => $model->format === ContentFormat::Article && $model->channel === Channel::WechatOfficial,
            ReviewTargetType::VideoProduction => $model->format === ContentFormat::Video && $model->channel === Channel::WechatChannels,
            default => false,
        };
        abort_unless($valid, 422, '审核目标与当前制作分支不匹配。');

        return [$content, $model, $type];
    }

    /**
     * 从服务器已保存正式内容构造 snapshot（按目标类型）。
     *
     * @return array<string, mixed>
     */
    public function snapshot(ContentItem $item, ContentBranch $branch, ReviewTargetType $target): array
    {
        $scope = fn ($query) => $query->where('project_id', $item->project_id)->where('content_item_id', $item->id)->where('content_branch_id', $branch->id);
        if ($target === ReviewTargetType::ArticleCopy) {
            $article = $scope(ArticleContent::query())->first();
            abort_unless($article, 422, '请先保存正式文章内容。');

            return ['title' => $item->title, ...$article->only(['headline', 'summary', 'body', 'image_suggestions', 'cover_suggestion'])];
        }
        if ($target === ReviewTargetType::VideoProduction) {
            $video = $scope(VideoContent::query())->first();
            abort_unless($video, 422, '请先保存正式视频制作内容。');
            $units = array_map(fn (array $unit) => ['source_page_id' => (int) $unit['source_page_id'], 'dynamic_instruction' => (string) ($unit['dynamic_instruction'] ?? ''), 'duration_seconds' => (float) ($unit['duration_seconds'] ?? VideoSourceService::DEFAULT_DURATION_SECONDS), 'note' => $unit['note'] ?? null], $video->units ?? []);

            return ['title' => $item->title, 'batch_instruction' => $video->batch_instruction, 'narration' => $video->narration, 'units' => $units,
                'total_duration' => array_sum(array_column($units, 'duration_seconds'))];
        }
        $pages = $item->contentPages()->where('project_id', $item->project_id)->orderBy('page_no')->orderBy('id')->get();
        if ($target === ReviewTargetType::ImagePostCopy) {
            abort_unless($pages->contains(fn ($page) => trim((string) $page->final_copy) !== ''), 422, '请先保存正式页面内容。');

            return ['title' => $item->title, 'core_message' => $item->core_message, 'story_line' => $item->story_line,
                'pages' => $pages->map(fn ($page) => [...$page->only(['page_no', 'narrative_role', 'new_fact', 'final_copy', 'visual_evidence', 'continuity', 'production_note']), 'page_type' => $page->page_type->value])->all()];
        }
        $draft = $scope(ImageBatchDraft::query())->first();
        abort_unless($draft, 422, '请先保存图片批次草稿。');
        $settings = $draft->unitSettings()->get()->keyBy('content_page_id');
        // P5-I03：每个 unit 的快照必须包含本页显式资产引用（稳定名称）。
        // 刻意不含 status：missing → existing 只是资产准备状态变化，不应使审核版本失效；
        // 而 妈妈 → 爸爸 是正式生产语义变化，必须形成新 version_label。
        $units = $pages->map(fn ($page) => ['page_no' => $page->page_no, 'source_label' => $this->videoSource->sourceLabel($page),
            'special_requirement' => $settings->get($page->id)?->special_requirement ?? '',
            'characters' => $this->imageBatchAssets->characters($page),
            'scene' => $this->imageBatchAssets->scene($page)])->all();
        abort_unless(trim((string) $draft->batch_instruction) !== '' || trim((string) $draft->batch_note) !== '' || collect($units)->contains(fn ($unit) => trim((string) $unit['special_requirement']) !== ''), 422, '请先保存图片批次制作说明或特殊要求。');

        return ['title' => $item->title, 'batch_instruction' => $draft->batch_instruction, 'batch_note' => $draft->batch_note, 'units' => $units];
    }

    /**
     * 递归：对非 list 数组按键名排序（保留 list 顺序），得到 canonical 结构。
     */
    public function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($entry) => $this->canonical($entry), $value);
    }

    /**
     * 由 (item, branch, target) 构造 snapshot 并计算稳定 version_label。
     */
    public function versionLabel(ContentItem $item, ContentBranch $branch, ReviewTargetType $target): string
    {
        return $this->hashVersion($this->canonical($this->snapshot($item, $branch, $target)));
    }

    /**
     * 由已构造的 snapshot 直接计算 version_label（避免重复构造 snapshot）。
     */
    public function versionLabelFromSnapshot(array $snapshot): string
    {
        return $this->hashVersion($this->canonical($snapshot));
    }

    private function hashVersion(array $canonical): string
    {
        return '内容版本 '.substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)), 0, 16);
    }
}
