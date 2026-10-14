<?php

namespace App\Services\V2;

use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Models\ReviewRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 审核请求的写入 / 读取服务。
 *
 * 轻量、无 WorkflowEngine：只负责「创建（含重复 pending 防护）」与「列表 / 详情读取」。
 *
 * 重复 pending 规则（Service 逻辑，最稳妥、最简单）：
 *   同一个 (content_branch_id, review_target_type, version_label) 已存在 pending 时，
 *   不重复创建第二条 pending——返回既有 pending（幂等，便于模块重复提交同一版本）。
 *   新 version_label 总是允许产生新审核请求。
 */
class ReviewRequestService
{
    /**
     * 创建审核请求。重复 pending（同分支 + 同目标类型 + 同版本）已存在时返回既有 pending。
     *
     * @param  array{review_target_type:string,review_target_label?:string,version_label:string,preview_summary?:string|null,preview?:mixed}  $data
     */
    public function create(Project $project, ContentBranch $branch, array $data): ReviewRequest
    {
        if ($branch->project_id !== $project->id) {
            throw new InvalidArgumentException('分支不属于当前项目，无法创建审核请求。');
        }

        $target = ReviewTargetType::from($data['review_target_type']);
        $label = $data['review_target_label'] ?? $target->label();

        return DB::transaction(function () use ($project, $branch, $data, $target, $label): ReviewRequest {
            // Lock an existing parent, including when no pending row exists yet.
            ContentBranch::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($branch->id);
            $existing = ReviewRequest::query()
                ->where('content_branch_id', $branch->id)
                ->where('review_target_type', $target->value)
                ->where('version_label', $data['version_label'])
                ->where('status', ReviewStatus::Pending)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                // 已存在 pending：不重复创建第二条 pending，返回既有（幂等）。
                return $existing;
            }

            return ReviewRequest::create([
                'project_id' => $project->id,
                'content_item_id' => $branch->content_item_id,
                'content_branch_id' => $branch->id,
                'review_target_type' => $target,
                'review_target_label' => $label,
                'version_label' => $data['version_label'],
                'preview_summary' => $data['preview_summary'] ?? null,
                'preview' => $data['preview'] ?? null,
                'status' => ReviewStatus::Pending,
                'submitted_at' => now(),
                'decided_at' => null,
            ]);
        }, 3);
    }

    /**
     * 列表：默认 pending；传 ReviewStatus 时按状态过滤。$status 为 null 表示不过滤（内部使用）。
     *
     * @return Collection<int, ReviewRequest>
     */
    public function list(Project $project, ?ReviewStatus $status = null)
    {
        $query = $project->reviewRequests()->latest('submitted_at');
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * 取详情：审核请求必须属于当前 Project，否则 404（跨 Project 不返回）。
     */
    public function findOrFail(Project $project, int $id): ReviewRequest
    {
        return $project->reviewRequests()->findOrFail($id);
    }
}
