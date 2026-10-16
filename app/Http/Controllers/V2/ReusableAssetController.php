<?php

namespace App\Http\Controllers\V2;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Exceptions\ReusableAssetNameConflictException;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\V2\ReusableAssetIndexService;
use App\Services\V2\ReusableAssetPresenter;
use App\Support\ProjectContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 人物 / 场景复用资产索引的 HTTP 入口（B06 + I06A + I06B + P5-I02）。
 *
 * 路由（写在 routes/web.php 的 V2 auth 分组内，最小差异）：
 *   GET   /projects/{project}/reusable-assets                    —— 正式 Inertia 页面
 *   GET   /api/projects/{project}/reusable-assets                —— 只读 JSON API（契约冻结）
 *   POST  /api/projects/{project}/reusable-assets                —— 新增人物 / 场景（201）
 *   PATCH /api/projects/{project}/reusable-assets/{asset}        —— 编辑 status / 预览图 / 说明（200）
 *
 * P5-I02 只补「新增 / 查看 / 编辑」：没有 DELETE、没有 rename、没有 change type、
 * 没有上传、没有 DAM / 文件夹 / 标签 / 版本 / 审批。
 *
 * 跨 Project → 404（ProjectContext 不匹配；PATCH 的 asset 也按 Project 作用域解析）；
 * 未登录 → 401（auth 中间件）。
 *
 * 分层：Controller 负责 ProjectContext / 校验 / 禁止字段；Service 负责写入 / 唯一性 / 返回模型。
 */
class ReusableAssetController extends Controller
{
    /** POST 禁止客户端提交的字段（服务器产生）。 */
    private const FORBIDDEN_CREATE_FIELDS = ['id', 'project_id', 'last_used_at', 'created_at', 'updated_at'];

    /** PATCH 禁止客户端提交的字段（其中 name / type 是稳定身份）。 */
    private const FORBIDDEN_UPDATE_FIELDS = ['id', 'project_id', 'last_used_at', 'name', 'type'];

    private const MAX_NAME = 200;

    private const MAX_THUMBNAIL_URL = 2048;

    private const MAX_DESCRIPTION = 2000;

    public function __construct(
        private readonly ReusableAssetIndexService $indexService,
        private readonly ReusableAssetPresenter $presenter,
    ) {}

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

        return response()->json($this->indexService->index($project));
    }

    /** 新增人物 / 场景：201 + 单个正式 item（无 data 包裹）。 */
    public function store(Request $request, Project $project): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $this->rejectForbiddenFields($request, self::FORBIDDEN_CREATE_FIELDS);

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($this->typeValues())],
            'name' => ['required', 'string', 'max:'.self::MAX_NAME],
            'status' => ['required', 'string', Rule::in($this->statusValues())],
            'thumbnail_url' => ['nullable', 'string', 'max:'.self::MAX_THUMBNAIL_URL, $this->safeThumbnailRule()],
            'description' => ['nullable', 'string', 'max:'.self::MAX_DESCRIPTION],
        ]);

        $name = trim((string) $data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => '名称不能为空。']);
        }

        try {
            $asset = $this->indexService->create(
                $project,
                $data['type'],
                $name,
                $data['status'],
                $this->nullableString($data['thumbnail_url'] ?? null),
                $this->nullableString($data['description'] ?? null),
            );
        } catch (ReusableAssetNameConflictException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return response()->json($this->presenter->item($asset), 201);
    }

    /** 编辑 status / 预览图 / 说明：200 + 更新后的正式 item。 */
    public function update(Request $request, Project $project, int $asset): JsonResponse
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $this->rejectForbiddenFields($request, self::FORBIDDEN_UPDATE_FIELDS);

        // 先按 Project 作用域解析（跨 Project → 404），再做字段校验。
        $model = $this->indexService->findInProject($project, $asset);

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'string', Rule::in($this->statusValues())],
            'thumbnail_url' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_THUMBNAIL_URL, $this->safeThumbnailRule()],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_DESCRIPTION],
        ]);

        $attributes = [];
        if (array_key_exists('status', $data)) {
            $attributes['status'] = $data['status'];
        }
        if (array_key_exists('thumbnail_url', $data)) {
            $attributes['thumbnail_url'] = $this->nullableString($data['thumbnail_url']);
        }
        if (array_key_exists('description', $data)) {
            $attributes['description'] = $this->nullableString($data['description']);
        }

        $model = $this->indexService->update($project, $model, $attributes);

        return response()->json($this->presenter->item($model));
    }

    /**
     * 出现禁止字段（含显式 null）一律 422，绝不静默忽略。
     *
     * @param  list<string>  $fields
     */
    private function rejectForbiddenFields(Request $request, array $fields): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if ($request->exists($field)) {
                $errors[$field] = ["不允许提交字段：{$field}（由服务器产生）。"];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * thumbnail_url 安全白名单：http:// / https:// / 站内以 / 开头的路径。
     *
     * 明确拒绝 data: / javascript: / file: 等不安全 scheme（也不做文件上传）。
     */
    private function safeThumbnailRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null) {
                return;
            }

            if (! is_string($value)) {
                $fail('预览图地址必须是字符串。');

                return;
            }

            $url = trim($value);

            if ($url === '' || str_starts_with($url, '/')) {
                return;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                $fail('预览图地址只允许 http:// / https:// 或站内以 / 开头的路径。');
            }
        };
    }

    /** 空字符串按 null 处理（前端清空输入框 = 清除）。 */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return list<string> */
    private function typeValues(): array
    {
        return array_map(static fn (ReusableAssetType $t): string => $t->value, ReusableAssetType::cases());
    }

    /** @return list<string> */
    private function statusValues(): array
    {
        return array_map(static fn (ReusableAssetStatus $s): string => $s->value, ReusableAssetStatus::cases());
    }
}
