<?php

namespace App\Services\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ArticleContent;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\ImageBatchDraft;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Models\VideoContent;
use Illuminate\Support\Facades\DB;

/** Manual submission of saved formal content; never runs a producer or writes source data. */
class ReviewSubmissionService
{
    public function __construct(private readonly ReviewRequestService $reviews) {}

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

    public function pending(ContentBranch $branch, ReviewTargetType $target): ?ReviewRequest
    {
        return ReviewRequest::query()->where('project_id', $branch->project_id)
            ->where('content_item_id', $branch->content_item_id)->where('content_branch_id', $branch->id)
            ->where('review_target_type', $target->value)->where('status', ReviewStatus::Pending)
            ->orderByDesc('id')->first();
    }

    public function submit(Project $project, int $item, int $branch, string $target): ReviewRequest
    {
        return DB::transaction(function () use ($project, $item, $branch, $target): ReviewRequest {
            [$content, $model, $type] = $this->resolve($project, $item, $branch, $target);
            ContentBranch::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($content, $model, $type);
            $canonical = $this->canonical($snapshot);
            $version = '内容版本 '.substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)), 0, 16);
            // Lock pending rows too: a concurrent decision must not turn a pending
            // into a decided version between this check and create's pending lookup.
            $decided = ReviewRequest::query()
                ->where('project_id', $project->id)
                ->where('content_branch_id', $model->id)
                ->where('review_target_type', $type->value)
                ->where('version_label', $version)
                ->orderByDesc('id')->lockForUpdate()->get()
                ->first(fn (ReviewRequest $review) => ! $review->isPending());
            if ($decided) {
                abort(422, $decided->status === ReviewStatus::ChangesRequested
                    ? '当前内容与被退回版本没有变化，请先修改并保存后再提交审核。'
                    : '当前内容版本已审核通过，无需重复提交。');
            }
            $items = [];
            foreach ($snapshot as $field => $value) {
                $items[] = ['label' => $this->label($field), 'value' => is_array($value) ? $this->describe($value) : (string) $value];
            }

            return $this->reviews->create($project, $model, [
                'review_target_type' => $type->value,
                'version_label' => $version,
                'preview_summary' => $content->title.' · '.$type->label(),
                'preview' => ['kind' => 'items', 'items' => $items],
            ]);
        }, 3);
    }

    private function snapshot(ContentItem $item, ContentBranch $branch, ReviewTargetType $target): array
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
            // Saved units are authoritative: current source pages must not add unsaved defaults.
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
        $units = $pages->map(fn ($page) => ['page_no' => $page->page_no, 'source_label' => (new VideoSourceService)->sourceLabel($page),
            'special_requirement' => $settings->get($page->id)?->special_requirement ?? ''])->all();
        abort_unless(trim((string) $draft->batch_instruction) !== '' || trim((string) $draft->batch_note) !== '' || collect($units)->contains(fn ($unit) => trim((string) $unit['special_requirement']) !== ''), 422, '请先保存图片批次制作说明或特殊要求。');

        return ['title' => $item->title, 'batch_instruction' => $draft->batch_instruction, 'batch_note' => $draft->batch_note, 'units' => $units];
    }

    /** Sort object keys recursively while retaining the order of every list. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($entry) => $this->canonical($entry), $value);
    }

    private function describe(array $values): string
    {
        $lines = [];
        foreach ($values as $key => $value) {
            $prefix = is_int($key) ? ($key + 1).'、' : $this->label($key).'：';
            $lines[] = $prefix.(is_array($value) ? "\n".$this->describe($value) : (string) $value);
        }

        return implode("\n", $lines);
    }

    private function label(string $field): string
    {
        return [
            'title' => '标题', 'core_message' => '核心表达', 'story_line' => '故事线', 'pages' => '页面', 'page_no' => '页序', 'page_type' => '页面类型',
            'narrative_role' => '页面职责', 'new_fact' => '新增事实', 'final_copy' => '最终文字', 'visual_evidence' => '关键画面证据', 'continuity' => '连续性', 'production_note' => '制作备注',
            'headline' => '文章标题', 'summary' => '摘要', 'body' => '正文', 'image_suggestions' => '配图建议', 'cover_suggestion' => '封面建议',
            'batch_instruction' => '整批制作说明', 'batch_note' => '批次备注', 'narration' => '完整旁白稿', 'units' => '制作单元', 'total_duration' => '总时长（秒）',
            'source_page_id' => '来源页', 'source_label' => '来源标签', 'special_requirement' => '特殊要求', 'dynamic_instruction' => '动态说明', 'duration_seconds' => '时长（秒）', 'note' => '备注',
            'position' => '位置', 'description' => '说明',
        ][$field] ?? $field;
    }
}
