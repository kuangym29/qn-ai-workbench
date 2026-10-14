<?php

namespace App\Http\Controllers\V2;

use App\Enums\ReviewDecision as ReviewDecisionEnum;
use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ReviewRequest;
use App\Services\V2\ReviewDecisionService;
use App\Services\V2\ReviewPresenter;
use App\Services\V2\ReviewRequestService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * V2 轻量审核中心 HTTP 入口。审核中心不是审批系统：只做「待我审核 / 通过 / 退回修改」。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，仅追加）：
 *   GET  /api/projects/{project}/reviews                      列表（默认 pending）
 *   GET  /api/projects/{project}/reviews/{review}             详情
 *   POST /api/projects/{project}/reviews/{review}/decision    提交决策
 *
 * 权限：登录(auth) + 当前 Project(session) + Review 属 Project（跨 Project → 404）。
 * 决策：仅 pending 可提交；已决策 → 409；changes_requested 必须 comment。
 */
class ReviewController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $raw = $request->query('status');
        if ($raw === null) {
            $status = ReviewStatus::Pending; // 默认只返回 pending
        } else {
            $status = ReviewStatus::tryFrom($raw);
            if ($status === null) {
                abort(422, '未知审核状态。');
            }
        }

        $items = (new ReviewRequestService)->list($project, $status);
        $presenter = new ReviewPresenter;

        return response()->json([
            'data' => $items->map(static fn (ReviewRequest $r): array => $presenter->toItem($r))->all(),
        ]);
    }

    public function show(Request $request, Project $project, int $review): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $model = (new ReviewRequestService)->findOrFail($project, $review);
        $model->load(['contentItem', 'contentBranch', 'decisions']);

        return response()->json([
            'data' => (new ReviewPresenter)->toDetail($model),
        ]);
    }

    public function decide(Request $request, Project $project, int $review): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $model = (new ReviewRequestService)->findOrFail($project, $review);

        $data = $request->validate([
            'decision' => ['required', Rule::in(array_map(static fn (ReviewDecisionEnum $c): string => $c->value, ReviewDecisionEnum::cases()))],
            'comment' => ['nullable', 'string'],
            'target_label' => ['nullable', 'string'],
        ]);

        $decisionEnum = ReviewDecisionEnum::from($data['decision']);

        try {
            $decision = (new ReviewDecisionService)->decide(
                $model,
                $decisionEnum,
                $data['comment'] ?? null,
                $data['target_label'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => (new ReviewPresenter)->toDecisionResult($decision),
        ], 200);
    }
}
