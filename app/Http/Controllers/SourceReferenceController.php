<?php

namespace App\Http\Controllers;

use App\Enums\SourceRole;
use App\Http\Requests\ItemSourceReferenceRequest;
use App\Http\Requests\ProjectSourceReferenceRequest;
use App\Http\Resources\SourceReferenceResource;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SourceReference;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DEV-W07 — SourceReference Lite 服务端 API。
 *
 * 两条作用域各自只管理一种 Role：
 *   * Project 级 → content_ledger / closing_line_registry / navigation_index；
 *   * Item 级    → final_image_copy / source_script。
 * 用另一条路径访问不属于自己的 Role 一律 404（资源不在该作用域内）。
 *
 * authority 永远由服务端按 SourceRole::defaultAuthority() 派生；role 变更时同步重算。
 * 路径只做相对路径的字符串校验与规范化，**不访问文件系统**。
 * 重复规则是「同一 scope + 同一 role + 同一规范化路径」422；同一路径被不同 Item 引用，
 * 以及同一 Item 拥有多条不同路径的 source_script，都是合法的。
 *
 * 本轮不提供 DELETE：来源引用属于 provenance 数据，不提供删除历史的普通入口。
 */
class SourceReferenceController extends Controller
{
    /** Project 级 Role（调用 Enum 判定，不复制硬编码清单）。 */
    private function projectRoles(): array
    {
        return array_values(array_filter(
            SourceRole::cases(),
            fn (SourceRole $role) => ! $role->isContentItemScoped(),
        ));
    }

    /** Item 级 Role。 */
    private function itemRoles(): array
    {
        return array_values(array_filter(
            SourceRole::cases(),
            fn (SourceRole $role) => $role->isContentItemScoped(),
        ));
    }

    /** 逐层解析 ContentItem（Item 级接口用），任一祖先错配 404。 */
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    /**
     * 在给定作用域内取一条引用：必须属于该 scope 的 Role 且归属正确，否则 404。
     */
    private function findScoped(Project $project, int $id, ?ContentItem $item): SourceReference
    {
        $query = $project->sourceReferences()->whereKey($id);

        $query = $item === null
            ? $query->whereNull('content_item_id')
            : $query->where('content_item_id', $item->id);

        $reference = $query->first();
        if ($reference === null) {
            abort(404);
        }

        $belongsToScope = $item === null
            ? ! $reference->role->isContentItemScoped()
            : $reference->role->isContentItemScoped();

        abort_unless($belongsToScope, 404);

        return $reference;
    }

    /**
     * 「同一 scope + 同一 role + 同一规范化路径」即重复。
     * 路径可被不同 Item 共享，因此必须带上 scope 条件。
     */
    private function assertNotDuplicate(
        Project $project,
        SourceRole $role,
        string $path,
        ?ContentItem $item,
        ?int $ignoreId = null,
    ): void {
        $query = $project->sourceReferences()
            ->where('role', $role->value)
            ->where('source_path', $path);

        $query = $item === null
            ? $query->whereNull('content_item_id')
            : $query->where('content_item_id', $item->id);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'source_path' => 'This source path is already referenced with the same role in this scope.',
            ]);
        }
    }

    /**
     * Canonical path to persist. Delegates to the request, which owns the single
     * normalisation definition (backslashes → `/`, collapsed separators, leading `./`
     * removed) and already validated that the result is a non-empty relative path.
     * Keeping one implementation is what guarantees the duplicate check compares the
     * same string that gets stored.
     */
    private function normalisePath(ProjectSourceReferenceRequest|ItemSourceReferenceRequest $request, string $field): string
    {
        return $request->canonicalSourcePath($field);
    }

    // ------------------------------------------------------------ Project 级

    public function projectIndex(Request $request, Project $project): AnonymousResourceCollection
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        $references = $project->sourceReferences()
            ->whereNull('content_item_id')
            ->whereIn('role', array_map(fn (SourceRole $r) => $r->value, $this->projectRoles()))
            ->orderBy('role')
            ->orderBy('source_path')
            ->get();

        return SourceReferenceResource::collection($references);
    }

    public function projectShow(Request $request, Project $project, int $sourceReference): SourceReferenceResource
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return new SourceReferenceResource($this->findScoped($project, $sourceReference, null));
    }

    public function projectStore(ProjectSourceReferenceRequest $request, Project $project): JsonResponse
    {
        $reference = DB::transaction(function () use ($request, $project) {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $role = SourceRole::from($request->validated('role'));
            $path = $this->normalisePath($request, 'source_path');

            $this->assertNotDuplicate($locked, $role, $path, null);

            return $locked->sourceReferences()->forceCreate([
                'content_item_id' => null,
                'role' => $role->value,
                // authority 永远服务端派生，客户端无法伪造。
                'authority' => $role->defaultAuthority()->value,
                'source_path' => $path,
                'note' => $request->validated('note'),
            ]);
        });

        return (new SourceReferenceResource($reference))->response()->setStatusCode(201);
    }

    public function projectUpdate(
        ProjectSourceReferenceRequest $request,
        Project $project,
        int $sourceReference,
    ): SourceReferenceResource {
        $updated = DB::transaction(function () use ($request, $project, $sourceReference) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $reference = $this->findScoped($project, $sourceReference, null);

            $role = $request->has('role') ? SourceRole::from($request->validated('role')) : $reference->role;
            $path = $request->has('source_path')
                ? $this->normalisePath($request, 'source_path')
                : $reference->source_path;

            $this->assertNotDuplicate($project, $role, $path, null, $reference->id);

            $attributes = [
                'role' => $role->value,
                'source_path' => $path,
                // role 变更时 authority 同步重算，不保留旧值。
                'authority' => $role->defaultAuthority()->value,
            ];
            if ($request->has('note')) {
                $attributes['note'] = $request->validated('note');
            }

            $reference->forceFill($attributes)->save();

            return $reference;
        });

        return new SourceReferenceResource($updated);
    }

    // -------------------------------------------------------------- Item 级

    public function itemIndex(
        Request $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
    ): AnonymousResourceCollection {
        $content = $this->item($request, $project, $column, $topic, $item);

        $references = $content->sourceReferences()
            ->whereIn('role', array_map(fn (SourceRole $r) => $r->value, $this->itemRoles()))
            ->orderBy('role')
            ->orderBy('source_path')
            ->get();

        return SourceReferenceResource::collection($references);
    }

    public function itemShow(
        Request $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
        int $sourceReference,
    ): SourceReferenceResource {
        $content = $this->item($request, $project, $column, $topic, $item);

        return new SourceReferenceResource($this->findScoped($project, $sourceReference, $content));
    }

    public function itemStore(
        ItemSourceReferenceRequest $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
    ): JsonResponse {
        $content = $this->item($request, $project, $column, $topic, $item);

        $reference = DB::transaction(function () use ($request, $project, $content) {
            // 锁顺序：ContentItem → SourceReference
            $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $role = SourceRole::from($request->validated('role'));
            $path = $this->normalisePath($request, 'source_path');

            $this->assertNotDuplicate($project, $role, $path, $locked);

            return SourceReference::query()->forceCreate([
                'project_id' => $project->id,
                'content_item_id' => $locked->id,
                'role' => $role->value,
                'authority' => $role->defaultAuthority()->value,
                'source_path' => $path,
                'note' => $request->validated('note'),
            ]);
        });

        return (new SourceReferenceResource($reference))->response()->setStatusCode(201);
    }

    public function itemUpdate(
        ItemSourceReferenceRequest $request,
        Project $project,
        int $column,
        int $topic,
        int $item,
        int $sourceReference,
    ): SourceReferenceResource {
        $content = $this->item($request, $project, $column, $topic, $item);

        $updated = DB::transaction(function () use ($request, $project, $content, $sourceReference) {
            $locked = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $reference = $this->findScoped($project, $sourceReference, $locked);

            $role = $request->has('role') ? SourceRole::from($request->validated('role')) : $reference->role;
            $path = $request->has('source_path')
                ? $this->normalisePath($request, 'source_path')
                : $reference->source_path;

            $this->assertNotDuplicate($project, $role, $path, $locked, $reference->id);

            $attributes = [
                'role' => $role->value,
                'source_path' => $path,
                'authority' => $role->defaultAuthority()->value,
            ];
            if ($request->has('note')) {
                $attributes['note'] = $request->validated('note');
            }

            $reference->forceFill($attributes)->save();

            return $reference;
        });

        return new SourceReferenceResource($updated);
    }
}
