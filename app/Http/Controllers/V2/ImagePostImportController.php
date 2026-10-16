<?php

namespace App\Http\Controllers\V2;

use App\Enums\ContentFormat;
use App\Enums\PageType;
use App\Http\Controllers\Controller;
use App\Models\ContentBranch;
use App\Models\ContentPage;
use App\Models\Project;
use App\Services\V2\ImagePostImportParser;
use App\Services\V2\ImagePostImportService;
use App\Services\V2\ImagePostManualService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * 小绿书（image_post）分支的 HTTP 入口，仅接线 B01 收口范围。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异）：
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/preview  (AI 导入预览，只读)
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/apply    (AI 导入确认落库)
 *   GET  /api/projects/{project}/items/{item}/branches/{branch}/image-post          (读取正式内容)
 *   PUT  /api/projects/{project}/items/{item}/branches/{branch}/image-post          (人工编辑保存)
 *
 * 所有端点先经 ProjectContext 做跨 Project 校验（不匹配 → 404），再校验分支 format = image_post（否则 → 422）。
 * 内部正式字段统一使用 core_message（UI 文案"核心表达"）。
 */
class ImagePostImportController extends Controller
{
    public function preview(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $data = $request->validate(['raw' => ['required', 'string']]);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $parsed = (new ImagePostImportParser())->parse($data['raw']);
            $result = (new ImagePostImportService())->preview($branchModel, $parsed);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function apply(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $data = $request->validate([
            'raw' => ['required', 'string'],
            'page_nos' => ['sometimes', 'array'],
            'page_nos.*' => ['integer', 'min:1'],
        ]);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $parsed = (new ImagePostImportParser())->parse($data['raw']);
            (new ImagePostImportService())->apply($branchModel, $parsed, $data['page_nos'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['status' => 'applied']], 200);
    }

    /**
     * 读取当前正式内容：文章级 3 字段 + 分支 2 字段 + 按 page_no 升序的页面列表。
     */
    public function show(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);
        $itemModel = $branchModel->contentItem;

        $pages = $itemModel->contentPages()->orderBy('page_no')->get()->map(static function (\App\Models\ContentPage $page): array {
            return [
                'id' => $page->id,
                'page_no' => $page->page_no,
                'page_type' => $page->page_type->value,
                'narrative_role' => $page->narrative_role,
                'new_fact' => $page->new_fact,
                'final_copy' => $page->final_copy,
                'visual_evidence' => $page->visual_evidence,
                'continuity' => $page->continuity,
                'production_note' => $page->production_note,
            ];
        })->all();

        return response()->json([
            'item' => [
                'id' => $itemModel->id,
                'title' => $itemModel->title,
                'core_message' => $itemModel->core_message,
                'story_line' => $itemModel->story_line,
            ],
            'branch' => [
                'id' => $branchModel->id,
                'channel' => $branchModel->channel->value,
                'format' => $branchModel->format->value,
            ],
            'pages' => $pages,
        ]);
    }

    /**
     * 人工编辑 / 保存当前正式内容。
     * Body 只允许：title / core_message / story_line / pages[]。
     * 变化页面才补版本快照；未变化页面不产生无意义版本；fixed_back_cover 受保护。
     */
    public function save(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'core_message' => ['sometimes', 'nullable', 'string'],
            'story_line' => ['sometimes', 'nullable', 'string'],
            'pages' => ['sometimes', 'array'],
            'pages.*.page_no' => ['required', 'integer', 'min:1'],
            'pages.*.page_type' => ['required', Rule::in(array_map(static fn (PageType $c): string => $c->value, PageType::cases()))],
            'pages.*.narrative_role' => ['sometimes', 'nullable', 'string'],
            'pages.*.new_fact' => ['sometimes', 'nullable', 'string'],
            'pages.*.final_copy' => ['sometimes', 'nullable', 'string'],
            'pages.*.visual_evidence' => ['sometimes', 'nullable', 'string'],
            'pages.*.continuity' => ['sometimes', 'nullable', 'string'],
            'pages.*.production_note' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => '校验失败', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        $pageNos = array_column($data['pages'] ?? [], 'page_no');
        if (count($pageNos) !== count(array_unique($pageNos))) {
            return response()->json(['message' => 'pages 中存在重复的 page_no。'], 422);
        }

        try {
            $warnings = (new ImagePostManualService())->save($branchModel, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['status' => 'saved'], 'warnings' => $warnings], 200);
    }

    private function resolveBranch(Project $project, int $item, int $branch): ContentBranch
    {
        $itemModel = $project->contentItems()->findOrFail($item);
        $branchModel = $itemModel->contentBranches()->where('project_id', $project->id)->findOrFail($branch);

        if ($branchModel->format !== ContentFormat::ImagePost) {
            abort(422, '仅 image_post 分支支持小绿书接口。');
        }

        return $branchModel;
    }
}
