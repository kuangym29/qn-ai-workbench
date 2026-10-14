<?php

namespace App\Services\V2;

use App\Models\ReviewDecision;
use App\Models\ReviewRequest;

/**
 * 审核中心 → 前端合同转换器（ReviewPresenter / Transformer）。
 *
 * 对齐 doubao/v2-review-center-frontend 的 ReviewItem / ReviewDetail / Preview / DecisionResult。
 * 不渲染复杂结构：preview 已是轻量可读的 {kind: text|lines|items, ...}，直接透传。
 */
class ReviewPresenter
{
    /**
     * ReviewItem：列表与详情共用的基础字段。
     *
     * @return array<string, mixed>
     */
    public function toItem(ReviewRequest $request): array
    {
        $branch = $request->contentBranch;

        return [
            'id' => $request->id,
            'content_item_id' => $request->content_item_id,
            'branch_id' => $request->content_branch_id,
            'status' => $request->status->value,
            'content_title' => $request->contentItem->title,
            'channel' => $branch->channel->value,
            'format' => $branch->format->value,
            'review_target_type' => $request->review_target_type->value,
            'review_target_label' => $request->review_target_label,
            'version_label' => $request->version_label,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'preview_summary' => $request->preview_summary,
        ];
    }

    /**
     * ReviewDetail：ReviewItem + preview。
     *
     * @return array<string, mixed>
     */
    public function toDetail(ReviewRequest $request): array
    {
        return array_merge($this->toItem($request), [
            'preview' => $request->preview,
        ]);
    }

    /**
     * DecisionResult：决策结果。
     *
     * @return array<string, mixed>
     */
    public function toDecisionResult(ReviewDecision $decision): array
    {
        return [
            'review_id' => $decision->review_request_id,
            'decision' => $decision->decision->value,
            'comment' => $decision->comment,
            'target_label' => $decision->target_label,
            'decided_at' => $decision->created_at?->toIso8601String(),
        ];
    }
}
