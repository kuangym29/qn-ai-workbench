<?php

namespace App\Http\Controllers\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Http\Controllers\Controller;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Services\V2\ArticleImportParser;
use App\Services\V2\ArticleImportService;
use App\Services\V2\ArticleManualService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * 公众号 · 图文文章（article）分支的 HTTP 入口，仅接线 B02 范围。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异）：
 *   GET    /api/projects/{project}/items/{item}/branches/{branch}/article          (读取正式内容)
 *   PUT    /api/projects/{project}/items/{item}/branches/{branch}/article          (人工编辑保存)
 *   POST   /api/projects/{project}/items/{item}/branches/{branch}/article/preview  (AI 导入预览，只读)
 *   POST   /api/projects/{project}/items/{item}/branches/{branch}/article/apply    (AI 导入确认落库)
 *
 * 所有端点先经 ProjectContext 做跨 Project 校验（不匹配 → 404），再校验分支
 * format = article 且 channel = wechat_official（否则 → 422）。
 * 内部正式字段统一使用 core_message（UI 文案"核心表达"）；不存在 core_expression 数据字段。
 */
class ArticleImportController extends Controller
{
    public function editor(Request $request, Project $project, int $item, int $branch): \Inertia\Response
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);
        $model = $branchModel->contentItem->load(['contentColumn', 'topic.inspiration']);

        return \Inertia\Inertia::render('V2/Article/ArticleEditor', [
            'projectId' => $project->id, 'itemId' => $model->id, 'branchId' => $branchModel->id,
            'columnName' => $model->contentColumn->name,
            'source' => [
                'topic' => $model->topic?->only(['id', 'title']),
                'inspiration' => $model->topic?->inspiration?->only(['id', 'body']),
            ],
        ]);
    }

    public function show(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        return response()->json((new ArticleManualService())->get($branchModel));
    }

    public function save(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'core_message' => ['sometimes', 'nullable', 'string'],
            'story_line' => ['sometimes', 'nullable', 'string'],
            'headline' => ['sometimes', 'nullable', 'string'],
            'summary' => ['sometimes', 'nullable', 'string'],
            'body' => ['sometimes', 'nullable', 'string'],
            'cover_suggestion' => ['sometimes', 'nullable', 'string'],
            'image_suggestions' => ['sometimes', 'array'],
            'image_suggestions.*.position' => ['sometimes', 'nullable', 'integer'],
            'image_suggestions.*.description' => ['sometimes', 'nullable', 'string'],
            'image_suggestions.*.note' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => '校验失败', 'errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();

        $warnings = (new ArticleManualService())->save($branchModel, $data);

        return response()->json(['data' => ['status' => 'saved'], 'warnings' => $warnings], 200);
    }

    public function preview(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $data = $request->validate(['raw' => ['required', 'string']]);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $parsed = (new ArticleImportParser())->parse($data['raw']);
            $result = (new ArticleImportService())->preview($branchModel, $parsed);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function apply(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $data = $request->validate(['raw' => ['required', 'string']]);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $parsed = (new ArticleImportParser())->parse($data['raw']);
            (new ArticleImportService())->apply($branchModel, $parsed);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['status' => 'applied']], 200);
    }

    private function resolveBranch(Project $project, int $item, int $branch): ContentBranch
    {
        $itemModel = $project->contentItems()->findOrFail($item);
        $branchModel = $itemModel->contentBranches()->where('project_id', $project->id)->findOrFail($branch);

        if ($branchModel->format !== ContentFormat::Article) {
            abort(422, '仅 article 分支支持图文文章接口。');
        }
        if ($branchModel->channel !== Channel::WechatOfficial) {
            abort(422, '仅公众号（wechat_official）渠道支持图文文章接口。');
        }

        return $branchModel;
    }
}
