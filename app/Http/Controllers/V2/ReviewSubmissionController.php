<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\V2\ReviewPresenter;
use App\Services\V2\ReviewSubmissionService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewSubmissionController extends Controller
{
    public function __construct(private readonly ReviewSubmissionService $submissions, private readonly ReviewPresenter $presenter) {}

    public function show(Request $request, Project $project, int $item, int $branch, string $target): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        [, $model, $type] = $this->submissions->resolve($project, $item, $branch, $target);
        $review = $this->submissions->pending($model, $type);

        return response()->json(['data' => ['pending' => $review !== null, 'review' => $review ? $this->presenter->toItem($review) : null]]);
    }

    public function store(Request $request, Project $project, int $item, int $branch, string $target): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $this->submissions->resolve($project, $item, $branch, $target);
        abort_unless($request->all() === [], 422, '提交审核只接受空请求；请先保存正式内容。');
        $review = $this->submissions->submit($project, $item, $branch, $target);
        $created = $review->wasRecentlyCreated;

        return response()->json(['data' => ['created' => $created, 'review' => $this->presenter->toItem($review)]], $created ? 201 : 200);
    }
}
