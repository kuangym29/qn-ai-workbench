<?php

namespace App\Services\V2;

use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Models\PublicationRecord;
use App\Models\ReviewRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * 手工"标记已发布"服务（PublicationService）。
 *
 * P5-I01 第一版只发布最终渠道成果：image_post_copy / article_copy / video_production。
 * image_batch 不是独立发布目标。
 *
 * 关键约束（来自任务规格）：
 *   - 发布只能由用户点击"标记已发布"产生；ReviewDecisionService / ReviewRequestService 不得偷偷创建。
 *   - 通过事务 + 锁 ContentBranch + 查 exact approved review + 数据库唯一约束，保证：
 *       当前 exact version 已发布 → 幂等 200 / created=false / 同 id；
 *       两个并发 POST → 数据库最终只 1 条；
 *       当前版本未审核 / pending / changes_requested / 旧版本 approved 但内容已改 → 422。
 *   - 拒绝客户端伪造字段（review_request_id / version_label / snapshot / content /
 *     published_at / published_by_user_id），这些全部由服务器产生。
 *   - 无副作用：不修改 ReviewRequest / ReviewDecision / 正式内容 / ReusableAsset.last_used_at，
 *     不创建 generation run / ImageBatch run。
 *   - append-only：PublicationRecord 一旦创建不可 UPDATE / DELETE（模型层强制）。
 */
class PublicationService
{
    public function __construct(private readonly ContentVersionResolver $resolver) {}

    /**
     * GET 发布状态：基于服务器当前保存版本实时计算，绝对无副作用。
     *
     * @return array<string, mixed>
     */
    public function state(Project $project, int $item, int $branch, string $target): array
    {
        abort_if(ReviewTargetType::tryFrom($target) === ReviewTargetType::ImageBatch, 422, '图片批次不是独立发布渠道，无法标记发布。');

        [$content, $model, $type] = $this->resolver->resolve($project, $item, $branch, $target);
        $versionLabel = $this->resolver->versionLabel($content, $model, $type);

        $approvedReview = ReviewRequest::query()
            ->where('project_id', $project->id)
            ->where('content_branch_id', $model->id)
            ->where('review_target_type', $type->value)
            ->where('version_label', $versionLabel)
            ->where('status', ReviewStatus::Approved)
            ->orderByDesc('id')->first();

        $record = PublicationRecord::query()
            ->where('content_branch_id', $model->id)
            ->where('review_target_type', $type->value)
            ->where('version_label', $versionLabel)
            ->orderByDesc('id')->first();

        $approved = $approvedReview !== null;
        $published = $record !== null;
        $canPublish = $approved && ! $published;
        $reason = match (true) {
            $published => '当前版本已发布。',
            $approved => '当前版本已审核通过，可以发布。',
            default => '当前版本尚未审核通过，请先提交审核并通过后再发布。',
        };

        return [
            'target_type' => $type->value,
            'current_version_label' => $versionLabel,
            'approved' => $approved,
            'review_request_id' => $approvedReview?->id,
            'published' => $published,
            'publication_id' => $record?->id,
            'published_at' => $record?->published_at?->toIso8601String(),
            'can_publish' => $canPublish,
            'reason' => $reason,
        ];
    }

    /**
     * POST 发布：事务 + 锁分支 + 查 exact approved + 幂等 + 抗并发唯一约束。
     * 拒绝客户端伪造字段。
     *
     * @param  array<string, mixed>  $payload
     * @return array{created: bool, publication: array<string, mixed>}
     */
    public function publish(Project $project, int $item, int $branch, string $target, array $payload): array
    {
        foreach (['review_request_id', 'version_label', 'snapshot', 'content', 'published_at', 'published_by_user_id'] as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                abort(422, '发布不接受客户端提供的 '.$forbidden.' 字段。');
            }
        }
        abort_if(ReviewTargetType::tryFrom($target) === ReviewTargetType::ImageBatch, 422, '图片批次不是独立发布渠道，无法标记发布。');

        return DB::transaction(function () use ($project, $item, $branch, $target): array {
            [$content, $model, $type] = $this->resolver->resolve($project, $item, $branch, $target);
            ContentBranch::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            $versionLabel = $this->resolver->versionLabel($content, $model, $type);

            // 服务器自查 exact approved review：project + branch + target + 当前 version + status=approved。
            $approvedReview = ReviewRequest::query()
                ->where('project_id', $project->id)
                ->where('content_branch_id', $model->id)
                ->where('review_target_type', $type->value)
                ->where('version_label', $versionLabel)
                ->where('status', ReviewStatus::Approved)
                ->orderByDesc('id')->first();
            if ($approvedReview === null) {
                abort(422, '当前保存版本尚未审核通过，请先提交审核并通过后再发布。');
            }

            // 幂等：同一 exact version 已发布 → 200 / created=false / 同一 id。
            $existing = PublicationRecord::query()
                ->where('content_branch_id', $model->id)
                ->where('review_target_type', $type->value)
                ->where('version_label', $versionLabel)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                return ['created' => false, 'publication' => $this->toArray($existing)];
            }

            try {
                $record = PublicationRecord::create([
                    'project_id' => $project->id,
                    'content_item_id' => $model->content_item_id,
                    'content_branch_id' => $model->id,
                    'review_request_id' => $approvedReview->id,
                    'review_target_type' => $type->value,
                    'version_label' => $versionLabel,
                    'published_by_user_id' => Auth::id(),
                    'published_at' => now(),
                ]);
            } catch (QueryException $e) {
                // 并发竞争兜底：唯一约束触发时回读既有记录，返回 200 / created=false。
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                $existing = PublicationRecord::query()
                    ->where('content_branch_id', $model->id)
                    ->where('review_target_type', $type->value)
                    ->where('version_label', $versionLabel)
                    ->orderByDesc('id')->firstOrFail();
                return ['created' => false, 'publication' => $this->toArray($existing)];
            }

            return ['created' => true, 'publication' => $this->toArray($record)];
        }, 3);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        if (in_array($code, ['23000', '23505', '1062', '2067', '2627'], true)) {
            return true;
        }
        $message = $e->getMessage();

        return str_contains($message, 'publication_records_branch_target_version_unique')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'integrity constraint violation');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(PublicationRecord $record): array
    {
        return [
            'id' => $record->id,
            'content_branch_id' => $record->content_branch_id,
            'review_request_id' => $record->review_request_id,
            'review_target_type' => $record->review_target_type->value,
            'version_label' => $record->version_label,
            'published_by_user_id' => $record->published_by_user_id,
            'published_at' => $record->published_at?->toIso8601String(),
        ];
    }
}
