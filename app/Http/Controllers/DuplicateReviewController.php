<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\Project;
use App\Services\DuplicateReviewService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DuplicateReviewController extends Controller
{
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    public function index(
        Request $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
        DuplicateReviewService $service,
    ): JsonResponse {
        $model = $this->item($request, $project, $column, $topic, $item);
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json(['data' => $service->review($model, (int) ($data['limit'] ?? 20))]);
    }

    public function store(
        Request $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
        DuplicateReviewService $service,
    ): JsonResponse {
        $model = $this->item($request, $project, $column, $topic, $item);
        $fields = ['cover_title', 'cover_subtitle', 'page_title', 'page_small_text', 'closing_line'];
        $data = $request->validate([
            'query_page_version_id' => ['required', 'integer', 'min:1'],
            'query_field' => ['required', Rule::in($fields)],
            'match_page_version_id' => ['required', 'integer', 'min:1'],
            'match_field' => ['required', Rule::in($fields)],
            'decision' => ['required', Rule::in(['confirmed_duplicate', 'ignored', 'false_positive'])],
            'note' => ['nullable', 'string', 'max:5000'],
            'project_id' => ['prohibited'],
            'content_item_id' => ['prohibited'],
            'decision_no' => ['prohibited'],
        ]);
        $decision = $service->appendDecision($model, $data);

        return response()->json(['data' => [
            'id' => $decision->id,
            'project_id' => $decision->project_id,
            'content_item_id' => $decision->content_item_id,
            'query_page_version_id' => $decision->query_page_version_id,
            'query_field' => $decision->query_field,
            'match_page_version_id' => $decision->match_page_version_id,
            'match_field' => $decision->match_field,
            'decision_no' => $decision->decision_no,
            'decision' => $decision->decision,
            'note' => $decision->note,
            'created_at' => $decision->created_at?->toISOString(),
        ]], 201);
    }
}
