<?php

namespace Tests\Feature;

use App\Enums\SourceRole;
use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SourceReference;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

/**
 * DEV-W07 — SourceReference Lite API。
 *
 * 覆盖两级作用域、authority 服务端派生、相对路径安全、重复规则与祖先 scope 404。
 */
class SourceReferenceApiTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    /** @return array{0: Project, 1: ContentColumn, 2: Topic, 3: ContentItem, 4: string, 5: string} */
    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $projectUrl = "/api/projects/{$project->id}/sources";
        $itemUrl = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/sources";

        return [$project, $column, $topic, $item, $projectUrl, $itemUrl];
    }

    // ------------------------------------------------------------ Project 级

    public function test_project_source_list_create_and_update(): void
    {
        [$project, , , , $projectUrl] = $this->context();

        $this->getJson($projectUrl)->assertOk()->assertExactJson(['data' => []]);

        $created = $this->postJson($projectUrl, [
            'role' => 'content_ledger',
            'source_path' => '00_项目入口/内容台账.md',
            'note' => '内容台账',
        ])->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.content_item_id', null)
            ->assertJsonPath('data.role', 'content_ledger')
            // authority 由服务端按 role 派生
            ->assertJsonPath('data.authority', 'index')
            ->assertJsonPath('data.note', '内容台账');
        $id = $created->json('data.id');

        $this->getJson("{$projectUrl}/{$id}")->assertOk()
            ->assertJsonPath('data.source_path', '00_项目入口/内容台账.md')
            // Resource 不得伪造这些字段
            ->assertJsonMissingPath('data.file_exists')
            ->assertJsonMissingPath('data.absolute_path')
            ->assertJsonMissingPath('data.sync_status')
            ->assertJsonMissingPath('data.hash')
            ->assertJsonMissingPath('data.version');

        $this->getJson($projectUrl)->assertOk();

        // 改 role → authority 必须同步重算，不保留旧值
        $this->patchJson("{$projectUrl}/{$id}", [
            'role' => 'closing_line_registry',
            'source_path' => '00_项目入口/收尾句台账.md',
        ])->assertOk()
            ->assertJsonPath('data.role', 'closing_line_registry')
            ->assertJsonPath('data.authority', 'reference')
            ->assertJsonPath('data.source_path', '00_项目入口/收尾句台账.md');

        $this->assertDatabaseHas('source_references', [
            'id' => $id, 'role' => 'closing_line_registry', 'authority' => 'reference',
        ]);
    }

    public function test_project_path_rejects_item_scoped_roles(): void
    {
        [, , , , $projectUrl] = $this->context();

        foreach (['final_image_copy', 'source_script'] as $role) {
            $this->postJson($projectUrl, ['role' => $role, 'source_path' => 'a/b.md'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_item_scoped_reference_is_404_through_project_path(): void
    {
        [, , , $item, $projectUrl] = $this->context();
        $copy = SourceReference::factory()->for($item->project, 'project')->for($item, 'contentItem')
            ->finalImageCopy()->create(['source_path' => 'scripts/final.md']);

        // Item 级记录不混入 Project 列表
        $this->getJson($projectUrl)->assertOk()->assertExactJson(['data' => []]);
        // 通过 Project 路径访问已存在的 Item 级记录 → 404
        $this->getJson("{$projectUrl}/{$copy->id}")->assertNotFound();
        $this->patchJson("{$projectUrl}/{$copy->id}", ['note' => 'x'])->assertNotFound();
    }

    // -------------------------------------------------------------- Item 级

    public function test_item_source_list_create_and_update(): void
    {
        [, , , $item, , $itemUrl] = $this->context();

        $this->getJson($itemUrl)->assertOk()->assertExactJson(['data' => []]);

        $created = $this->postJson($itemUrl, [
            'role' => 'final_image_copy',
            'source_path' => '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md',
        ])->assertCreated()
            ->assertJsonPath('data.content_item_id', $item->id)
            ->assertJsonPath('data.authority', 'authoritative');
        $id = $created->json('data.id');

        $this->getJson("{$itemUrl}/{$id}")->assertOk()->assertJsonPath('data.role', 'final_image_copy');

        // source_script 改 authority：evidence
        $script = $this->postJson($itemUrl, [
            'role' => 'source_script', 'source_path' => 'scripts/v2.md',
        ])->assertCreated()->assertJsonPath('data.authority', 'evidence');

        // 改 role → authority 同步重算
        $this->patchJson("{$itemUrl}/{$script->json('data.id')}", ['role' => 'final_image_copy'])
            ->assertOk()
            ->assertJsonPath('data.role', 'final_image_copy')
            ->assertJsonPath('data.authority', 'authoritative');

        $this->getJson($itemUrl)->assertOk();
    }

    public function test_item_path_rejects_project_scoped_roles(): void
    {
        [, , , , , $itemUrl] = $this->context();

        foreach (['content_ledger', 'closing_line_registry', 'navigation_index'] as $role) {
            $this->postJson($itemUrl, ['role' => $role, 'source_path' => 'a/b.md'])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_project_scoped_reference_is_404_through_item_path(): void
    {
        [$project, , , , $projectUrl, $itemUrl] = $this->context();
        $ledger = SourceReference::factory()->for($project)->contentLedger()->create(['source_path' => 'ledger.md']);

        $this->getJson($itemUrl)->assertOk()->assertExactJson(['data' => []]);
        $this->getJson("{$itemUrl}/{$ledger->id}")->assertNotFound();
        $this->patchJson("{$itemUrl}/{$ledger->id}", ['note' => 'x'])->assertNotFound();
    }

    // ---------------------------------------------------- authority / 伪造字段

    public function test_authority_and_ownership_keys_cannot_be_forged(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();

        foreach (['authority', 'project_id', 'content_item_id', 'id'] as $field) {
            $this->postJson($projectUrl, [
                'role' => 'content_ledger', 'source_path' => 'a/b.md', $field => 'authoritative',
            ])->assertUnprocessable()->assertJsonValidationErrors($field);

            $this->postJson($itemUrl, [
                'role' => 'source_script', 'source_path' => 'a/b.md', $field => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);

            $this->patchJson($projectUrl.'/1', [$field => 1])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_authority_always_follows_role_default(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();

        $map = [
            'content_ledger' => 'index',
            'closing_line_registry' => 'reference',
            'navigation_index' => 'index',
        ];
        foreach ($map as $role => $authority) {
            $id = $this->postJson($projectUrl, ['role' => $role, 'source_path' => "{$role}.md"])
                ->assertCreated()->json('data.id');
            $this->assertSame(
                $authority,
                SourceRole::from($role)->defaultAuthority()->value,
                'enum default must match the API result',
            );
            $this->assertDatabaseHas('source_references', ['id' => $id, 'authority' => $authority]);
        }

        foreach (['final_image_copy' => 'authoritative', 'source_script' => 'evidence'] as $role => $authority) {
            $id = $this->postJson($itemUrl, ['role' => $role, 'source_path' => "{$role}.md"])
                ->assertCreated()->json('data.id');
            $this->assertDatabaseHas('source_references', ['id' => $id, 'authority' => $authority]);
        }
    }

    // -------------------------------------------------------------- 路径安全

    public function test_absolute_paths_traversal_and_unc_are_rejected(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();

        $bad = [
            'C:\\Users\\me\\script.md',
            'c:/users/me/script.md',
            '/etc/passwd',
            '\\\\server\\share\\script.md',
            '../outside.md',
            'a/../../outside.md',
            'a\\..\\..\\outside.md',
            '   ',
        ];

        foreach ($bad as $path) {
            $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => $path])
                ->assertUnprocessable()->assertJsonValidationErrors('source_path');
            $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => $path])
                ->assertUnprocessable()->assertJsonValidationErrors('source_path');
        }

        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_backslashes_are_normalised_to_forward_slashes(): void
    {
        [, , , , $projectUrl] = $this->context();

        $this->postJson($projectUrl, [
            'role' => 'content_ledger',
            'source_path' => '00_项目入口\\\\子目录\\\\内容台账.md',
        ])->assertCreated()->assertJsonPath('data.source_path', '00_项目入口/子目录/内容台账.md');

        // 规范化后与已存记录重复 → 422
        $this->postJson($projectUrl, [
            'role' => 'content_ledger',
            'source_path' => '00_项目入口/子目录/内容台账.md',
        ])->assertUnprocessable()->assertJsonValidationErrors('source_path');

        $this->assertDatabaseCount('source_references', 1);
    }

    public function test_chinese_paths_are_allowed(): void
    {
        [, , , , $projectUrl] = $this->context();

        $this->postJson($projectUrl, [
            'role' => 'navigation_index',
            'source_path' => '01_2.5D家庭IP形象/导航索引.md',
        ])->assertCreated()->assertJsonPath('data.source_path', '01_2.5D家庭IP形象/导航索引.md');
    }

    // ---------------------------------------------------------------- 重复

    public function test_duplicate_in_same_scope_and_role_is_rejected(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();

        $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => 'ledger.md'])->assertCreated();
        $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => 'ledger.md'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_path');

        $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => 's.md'])->assertCreated();
        $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => 's.md'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_path');

        // 同 scope 不同 role 不算重复
        $this->postJson($projectUrl, ['role' => 'navigation_index', 'source_path' => 'ledger.md'])->assertCreated();

        $this->assertDatabaseCount('source_references', 3);
    }

    public function test_same_path_may_be_shared_by_multiple_items(): void
    {
        [$project, $column, $topic, $item, , $itemUrl] = $this->context();
        $path = '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md';

        $this->postJson($itemUrl, ['role' => 'final_image_copy', 'source_path' => $path])->assertCreated();

        // 另一个 ContentItem 引用同一路径是正式能力，不算重复
        $otherItem = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $otherUrl = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$otherItem->id}/sources";
        $this->postJson($otherUrl, ['role' => 'final_image_copy', 'source_path' => $path])->assertCreated();

        $this->assertSame(2, SourceReference::query()->where('source_path', $path)->count());
    }

    public function test_one_item_may_reference_multiple_source_script_versions(): void
    {
        [, , , , , $itemUrl] = $this->context();

        foreach (['scripts/v2.md', 'scripts/v6.md'] as $path) {
            $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => $path])->assertCreated();
        }
        $this->assertDatabaseCount('source_references', 2);
    }

    public function test_patch_into_an_existing_duplicate_is_rejected(): void
    {
        [, , , , $projectUrl] = $this->context();

        $a = $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => 'a.md'])->assertCreated()->json('data.id');
        $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => 'b.md'])->assertCreated();

        $this->patchJson("{$projectUrl}/{$a}", ['source_path' => 'b.md'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_path');

        // 保留自身路径的 PATCH 是允许的
        $this->patchJson("{$projectUrl}/{$a}", ['source_path' => 'a.md', 'note' => '同一路径'])
            ->assertOk()->assertJsonPath('data.source_path', 'a.md');
    }

    // ------------------------------------------------------------------ Scope

    public function test_cross_project_and_wrong_ancestors_return_404(): void
    {
        [$project, , , $item, $projectUrl, $itemUrl] = $this->context();
        $ledger = SourceReference::factory()->for($project)->contentLedger()->create(['source_path' => 'l.md']);
        $script = SourceReference::factory()->for($project)->for($item, 'contentItem')
            ->sourceScript()->create(['source_path' => 's.md']);

        $other = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($other)->create();
        $otherTopic = Topic::factory()->create(['project_id' => $other->id, 'content_column_id' => $otherColumn->id]);
        $otherItem = ContentItem::factory()->create([
            'project_id' => $other->id, 'content_column_id' => $otherColumn->id, 'topic_id' => $otherTopic->id,
        ]);

        // Session 仍指向 $project，用别的 Project 访问 → 404
        $foreignProjectUrl = "/api/projects/{$other->id}/sources";
        $this->getJson($foreignProjectUrl)->assertNotFound();
        $this->postJson($foreignProjectUrl, ['role' => 'content_ledger', 'source_path' => 'x.md'])->assertNotFound();
        $this->getJson("{$foreignProjectUrl}/{$ledger->id}")->assertNotFound();

        $foreignItemUrl = "/api/projects/{$other->id}/columns/{$otherColumn->id}/topics/{$otherTopic->id}/items/{$otherItem->id}/sources";
        $this->getJson($foreignItemUrl)->assertNotFound();
        $this->postJson($foreignItemUrl, ['role' => 'source_script', 'source_path' => 'x.md'])->assertNotFound();

        // 正确 Project 但错误篇目 → 404
        $wrongItemUrl = "/api/projects/{$project->id}/columns/{$otherColumn->id}/topics/{$otherTopic->id}/items/{$item->id}/sources";
        $this->getJson($wrongItemUrl)->assertNotFound();
        $this->getJson("{$itemUrl}/999999")->assertNotFound();
    }

    public function test_no_delete_endpoint_exists(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();
        $projectRef = $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => 'l.md'])
            ->assertCreated()->json('data.id');
        $itemRef = $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => 's.md'])
            ->assertCreated()->json('data.id');

        // {sourceReference} 只声明了 GET / PATCH，因此 DELETE 要么无匹配路由(404)，
        // 要么命中已声明路由但方法不允许(405)。两者都表示「没有删除 API」，
        // 且两条记录都必须原样保留。
        $this->assertContains(
            $this->json('DELETE', "{$projectUrl}/{$projectRef}")->status(),
            [404, 405],
        );
        $this->assertContains(
            $this->json('DELETE', "{$itemUrl}/{$itemRef}")->status(),
            [404, 405],
        );

        $this->assertDatabaseHas('source_references', ['id' => $projectRef]);
        $this->assertDatabaseHas('source_references', ['id' => $itemRef]);
    }

    public function test_existing_imported_references_are_readable_and_editable(): void
    {
        // DEV-008B 导入的历史引用必须能只读加载并编辑，且不触发任何补建。
        [$project, , , $item, $projectUrl, $itemUrl] = $this->context();
        SourceReference::factory()->for($project)->contentLedger()->create([
            'source_path' => '00_项目入口/内容总台账.md', 'note' => '历史导入',
        ]);
        $copy = SourceReference::factory()->for($project)->for($item, 'contentItem')
            ->finalImageCopy()->create(['source_path' => '生活小能力/图文/最终上图文案.md']);

        $this->getJson($projectUrl)->assertOk();
        $this->getJson($itemUrl)->assertOk();

        $this->patchJson("{$itemUrl}/{$copy->id}", ['note' => '已核对'])
            ->assertOk()->assertJsonPath('data.note', '已核对');
        $this->assertDatabaseCount('source_references', 2);
    }

    public function test_item_scope_requires_confirmed_session_project(): void
    {
        [, , , , $projectUrl] = $this->context();

        // 切换 Session 到另一个 Project 后，原 Project 全部 404
        $other = Project::factory()->create();
        $this->postJson("/api/projects/{$other->id}/select")->assertOk();
        $this->getJson($projectUrl)->assertNotFound();
    }

    public function test_paths_that_normalise_to_empty_are_rejected(): void
    {
        [, , , , $projectUrl, $itemUrl] = $this->context();

        // 这些输入原始串非空，能通过基础安全检查，但规范化后为空，
        // 绝不允许落库成 source_path = ''。
        $emptyAfterNormalising = ['', '   ', './', '././', './././', './/.', '.\\.\\.'];

        foreach ($emptyAfterNormalising as $path) {
            $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => $path])
                ->assertUnprocessable()->assertJsonValidationErrors('source_path');
            $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => $path])
                ->assertUnprocessable()->assertJsonValidationErrors('source_path');
        }

        $this->assertDatabaseCount('source_references', 0);
    }

    public function test_dot_slash_prefix_is_still_allowed_and_normalised_away(): void
    {
        // 只拒绝「规范化后为空」，不是禁止正常的 ./ 前缀。
        [, , , , $projectUrl, $itemUrl] = $this->context();

        $this->postJson($projectUrl, ['role' => 'content_ledger', 'source_path' => './a/b.md'])
            ->assertCreated()->assertJsonPath('data.source_path', 'a/b.md');

        $this->postJson($itemUrl, ['role' => 'source_script', 'source_path' => './a/b.md'])
            ->assertCreated()->assertJsonPath('data.source_path', 'a/b.md');

        $this->assertDatabaseCount('source_references', 2);
        $this->assertDatabaseMissing('source_references', ['source_path' => '']);
    }
}
