<?php

namespace App\Services\V2;

use App\Enums\ReviewDecision as ReviewDecisionEnum;
use App\Enums\ReviewStatus;
use App\Models\ReviewDecision;
use App\Models\ReviewRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 审核决策服务。
 *
 * 规则（轻量，无 ApprovalEngine）：
 *   - 仅 pending 审核可提交决策；已 approved / changes_requested → 抛 DomainException（API 层转 409）。
 *   - changes_requested 必须带 comment；approved 的 comment 可空。
 *   - review_decisions append-only：只新增，不更新、不删除；decision_no 从 1 递增。
 *   - 落库后更新 review_request.status 与 decided_at（保存当前状态，便于筛 pending）。
 */
class ReviewDecisionService
{
    /**
     * 记录一次审核决策。返回新建的 append-only 决策记录。
     *
     * @throws \DomainException 已非 pending（重复审核）→ API 层转 409。
     * @throws InvalidArgumentException changes_requested 缺 comment → API 层转 422。
     */
    public function decide(
        ReviewRequest $request,
        ReviewDecisionEnum $decision,
        ?string $comment,
        ?string $targetLabel
    ): ReviewDecision {
        if (! $request->isPending()) {
            throw new \DomainException('审核已结束，不能重复决策。');
        }
        if ($decision === ReviewDecisionEnum::ChangesRequested && ($comment === null || trim($comment) === '')) {
            throw new InvalidArgumentException('退回修改必须填写批注。');
        }

        return DB::transaction(function () use ($request, $decision, $comment, $targetLabel): ReviewDecision {
            $request = ReviewRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! $request->isPending()) {
                throw new \DomainException('审核已结束，不能重复决策。');
            }

            $nextNo = (int) $request->decisions()->max('decision_no') + 1;

            $record = $request->decisions()->create([
                'decision_no' => $nextNo,
                'decision' => $decision,
                'comment' => $comment,
                'target_label' => $targetLabel,
            ]);

            $request->update([
                'status' => $decision === ReviewDecisionEnum::Approved
                    ? ReviewStatus::Approved
                    : ReviewStatus::ChangesRequested,
                'decided_at' => now(),
            ]);

            return $record;
        }, 3);
    }
}
