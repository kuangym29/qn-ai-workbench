<?php

namespace App\Services\V2;

use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Models\ReviewRequest;
use Illuminate\Support\Facades\DB;

/** Manual submission of saved formal content; never runs a producer or writes source data. */
class ReviewSubmissionService
{
    public function __construct(
        private readonly ReviewRequestService $reviews,
        private readonly ContentVersionResolver $resolver,
    ) {}

    public function resolve(Project $project, int $item, int $branch, string $target): array
    {
        return $this->resolver->resolve($project, $item, $branch, $target);
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
            [$content, $model, $type] = $this->resolver->resolve($project, $item, $branch, $target);
            ContentBranch::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->resolver->snapshot($content, $model, $type);
            $version = $this->resolver->versionLabelFromSnapshot($snapshot);
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
            'position' => '位置', 'description' => '说明', 'characters' => '人物', 'scene' => '场景',
        ][$field] ?? $field;
    }
}
