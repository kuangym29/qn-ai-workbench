<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Services\V2\ImagePostImportParser;
use App\Services\V2\ImagePostImportService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * 小绿书 AI 导入的 HTTP 入口，仅接线 B01 范围。
 *
 * 两个端点都先经 ProjectContext 做跨 Project 校验（不匹配 → 404），
 * 再解析 → 预览（只读）/ 应用（落库）。结构非法或非 image_post 分支 → 422。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异）：
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/preview
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/image-post/apply
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

    private function resolveBranch(Project $project, int $item, int $branch): ContentBranch
    {
        $itemModel = $project->contentItems()->findOrFail($item);

        return $itemModel->contentBranches()->where('project_id', $project->id)->findOrFail($branch);
    }
}
