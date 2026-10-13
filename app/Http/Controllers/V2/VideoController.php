<?php

namespace App\Http\Controllers\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Http\Controllers\Controller;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Services\V2\VideoManualService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * V2 视频号视频制作（video branch）HTTP 入口。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，仅追加）：
 *   GET  /api/projects/{project}/items/{item}/branches/{branch}/video                  读取视频制作
 *   PUT  /api/projects/{project}/items/{item}/branches/{branch}/video                  人工保存（白名单）
 *   POST /api/projects/{project}/items/{item}/branches/{branch}/video/source-change   继续沿用来源（keep）
 *
 * 权限：登录(auth) + 当前 Project(session) + Item/Branch 属 Project（跨 Project → 404）。
 * 格式：仅 channel = wechat_channels 且 format = video；其它 branch → 422。
 *
 * 第一阶段不含真实视频生成 / Seedance / 配音 / 字幕 / 剪辑 / 复杂 Timeline Editor。
 */
class VideoController extends Controller
{
    public function page(Request $request, Project $project, int $item, int $branch): Response
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $model = $this->resolveBranch($project, $item, $branch);

        return Inertia::render('V2/Video/VideoEditor', [
            'projectId' => $project->id, 'itemId' => $item, 'branchId' => $model->id,
        ]);
    }

    public function show(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        try {
            $result = (new VideoManualService)->read($this->resolveBranch($project, $item, $branch));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    /**
     * PUT 白名单：batch_instruction / narration / units[]。
     * 禁止写入 title / source_label / order / source_changed / source_change_summary /
     * timeline / total_duration —— 出现即 422。
     */
    public function save(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        if (array_diff(array_keys($request->all()), ['batch_instruction', 'narration', 'units']) !== []) {
            return response()->json(['message' => '仅允许保存整批说明、旁白和片段制作信息。'], 422);
        }

        $data = $request->validate([
            'batch_instruction' => ['sometimes', 'nullable', 'string'],
            'narration' => ['sometimes', 'nullable', 'string'],
            'units' => ['sometimes', 'array'],
            'units.*' => ['array:source_page_id,dynamic_instruction,duration_seconds,note'],
            'units.*.source_page_id' => ['required', 'integer'],
            'units.*.dynamic_instruction' => ['sometimes', 'nullable', 'string'],
            'units.*.duration_seconds' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:600'],
            'units.*.note' => ['sometimes', 'nullable', 'string'],
        ], [], [
            'units.*.duration_seconds.min' => '视频时长不能为负数。',
        ]);

        $forbidden = ['title', 'source_label', 'order', 'source_changed', 'source_change_summary', 'timeline', 'total_duration'];
        $attempted = array_values(array_intersect($forbidden, array_keys($request->all())));
        if ($attempted !== []) {
            return response()->json([
                'message' => '这些字段由系统计算，不能通过 PUT 写入：'.implode('、', $attempted),
            ], 422);
        }

        try {
            $changed = (new VideoManualService)->save($branchModel, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['status' => 'saved', 'changed' => $changed]], 200);
    }

    /**
     * 继续沿用来源：body { "action": "keep" }。
     * 只更新 source_fingerprint 为当前来源版本，不改 batch_instruction / narration / units。
     * 第一阶段只支持 keep。
     */
    public function sourceChange(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $data = $request->validate([
            'action' => ['required', 'in:keep'],
        ]);

        try {
            (new VideoManualService)->keepSource($branchModel);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['status' => $data['action']]], 200);
    }

    private function resolveBranch(Project $project, int $item, int $branch): ContentBranch
    {
        $itemModel = $project->contentItems()->findOrFail($item);
        $branchModel = $itemModel->contentBranches()->where('project_id', $project->id)->findOrFail($branch);

        if ($branchModel->format !== ContentFormat::Video) {
            abort(422, '仅 video 分支支持视频制作接口。');
        }
        if ($branchModel->channel !== Channel::WechatChannels) {
            abort(422, '仅视频号（wechat_channels）分支支持视频制作接口。');
        }

        return $branchModel;
    }
}
