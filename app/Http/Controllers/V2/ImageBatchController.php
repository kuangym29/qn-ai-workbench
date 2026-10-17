<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\ContentBranch;
use App\Models\Project;
use App\Services\V2\ImageBatchCopyTaskService;
use App\Services\V2\ImageBatchRunService;
use App\Services\V2\ImageBatchSourceChangeService;
use App\Services\V2\ImageBatchWorkspaceService;
use App\Support\ProjectContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * 图片批次生产（image_post + wechat_official 分支）HTTP 入口。
 *
 * 路由（routes/web.php 仅追加，不改 ImagePost / Video / Review）：
 *   GET   /api/projects/{project}/items/{item}/branches/{branch}/image-batch
 *   PUT   /api/projects/{project}/items/{item}/branches/{branch}/image-batch
 *   POST  /api/projects/{project}/items/{item}/branches/{branch}/image-batch/run
 *   POST  /api/projects/{project}/items/{item}/branches/{branch}/image-batch/copy-task
 *   POST  /api/projects/{project}/items/{item}/branches/{branch}/image-batch/units/{unit}/source-change
 *
 * 权限：登录（路由组）+ 当前 Project（session）+ item 属 Project + branch 属 item；
 *       跨 Project / 不存在 → 404；分支 channel / format 不符 → 422。
 *
 * 第一阶段不接真实生图：run 只创建执行记录并返回 queued。
 */
class ImageBatchController extends Controller
{
    public function __construct(
        private readonly ImageBatchWorkspaceService $workspaceService,
        private readonly ImageBatchRunService $runService,
        private readonly ImageBatchCopyTaskService $copyTaskService,
        private readonly ImageBatchSourceChangeService $sourceChangeService,
    ) {}

    public function show(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $workspace = $this->workspaceService->workspace($branchModel);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($workspace);
    }

    public function save(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $forbidden = $this->forbiddenKeys($request);
        if ($forbidden !== []) {
            return response()->json([
                'message' => '禁止提交字段：'.implode(', ', $forbidden).'。',
            ], 422);
        }

        $data = $request->validate([
            'batch_instruction' => ['sometimes', 'string'],
            'batch_note' => ['sometimes', 'nullable', 'string'],
            'units' => ['sometimes', 'array'],
            'units.*.id' => ['required_with:units', 'integer'],
            'units.*.special_requirement' => ['sometimes', 'nullable', 'string'],
            // P5-I03：本页显式引用了哪些人物 / 哪个场景。
            //   数组 → 替换人物引用（[] = 清空）；null → 清空场景；缺省 → 保持已有引用。
            'units.*.character_asset_ids' => ['sometimes', 'array'],
            'units.*.character_asset_ids.*' => ['integer', 'min:1'],
            'units.*.scene_asset_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->workspaceService->saveDraft($branchModel, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'saved']);
    }

    public function run(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $data = $request->validate([
            'mode' => ['required', 'in:full,trial,partial'],
            'unit_ids' => ['sometimes', 'array'],
            'unit_ids.*' => ['integer', 'min:1'],
        ]);

        try {
            $result = $this->runService->run($branchModel, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result, 201);
    }

    public function copyTask(Request $request, Project $project, int $item, int $branch): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        try {
            $result = $this->copyTaskService->task($branchModel);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function sourceChange(Request $request, Project $project, int $item, int $branch, int $unit): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);
        $branchModel = $this->resolveBranch($project, $item, $branch);

        $data = $request->validate([
            'action' => ['required', 'in:keep,regenerate'],
        ]);

        try {
            $setting = $this->sourceChangeService->resolve($branchModel, $unit, $data['action']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => '页面单元不存在。'], 404);
        }

        return response()->json([
            'status' => 'resolved',
            'unit_id' => $setting->id,
            'status_value' => $setting->status->value,
        ]);
    }

    /**
     * PUT 白名单之外的字段一律拒绝（不做静默忽略，避免前端误以为已写入）。
     *
     * 显式资产引用的写入合同只有 units[].character_asset_ids / units[].scene_asset_id；
     * characters / scene / asset_names / asset_status / project_id / page_id /
     * reusable_asset 对象 / last_used_at 等都不是写入合同，出现即 422。
     *
     * @return list<string>
     */
    private function forbiddenKeys(Request $request): array
    {
        $forbidden = array_values(
            array_diff(array_keys($request->all()), ['batch_instruction', 'batch_note', 'units'])
        );

        foreach ((array) $request->input('units', []) as $index => $patch) {
            foreach (array_diff(array_keys((array) $patch), ['id', 'special_requirement', 'character_asset_ids', 'scene_asset_id']) as $key) {
                $forbidden[] = 'units.'.$index.'.'.$key;
            }
        }

        return $forbidden;
    }

    private function resolveBranch(Project $project, int $item, int $branch): ContentBranch
    {
        $itemModel = $project->contentItems()->findOrFail($item);

        return $itemModel->contentBranches()->findOrFail($branch);
    }
}
