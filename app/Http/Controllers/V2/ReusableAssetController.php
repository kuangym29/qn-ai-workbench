<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\V2\ReusableAssetIndexService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 人物 / 场景复用资产索引的 HTTP 入口（B06）。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异，只追加一条）：
 *   GET /api/projects/{project}/reusable-assets
 *
 * 只读索引：本阶段没有公开 CRUD、没有后台管理、没有上传。
 * 跨 Project → 404（ProjectContext 不匹配）；未登录 → 401（auth 中间件）。
 */
class ReusableAssetController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return response()->json((new ReusableAssetIndexService)->index($project));
    }
}
