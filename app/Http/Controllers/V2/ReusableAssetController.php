<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\V2\ReusableAssetIndexService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 人物 / 场景复用资产索引的 HTTP 入口（B06 + I06A）。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异）：
 *   GET /projects/{project}/reusable-assets              —— 正式 Inertia 页面
 *   GET /api/projects/{project}/reusable-assets          —— 只读 JSON API
 *
 * 只读索引：本阶段没有公开 CRUD、没有后台管理、没有上传。
 * 跨 Project → 404（ProjectContext 不匹配）；未登录 → 401（auth 中间件）。
 */
class ReusableAssetController extends Controller
{
    /** 正式页面：只传 projectId，数据由前端通过只读 API 拉取。 */
    public function page(Request $request, Project $project): Response
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return Inertia::render('V2/ReusableAssets/ReusableAssetIndex', [
            'projectId' => $project->id,
        ]);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return response()->json((new ReusableAssetIndexService)->index($project));
    }
}
