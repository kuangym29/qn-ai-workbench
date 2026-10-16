<?php

namespace Tests\Feature\V2;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5-I02：人物 / 场景复用资产索引的公开管理入口。
 *
 *   GET   /api/projects/{project}/reusable-assets          契约冻结（{characters, scenes}，无 data 包裹）
 *   POST  /api/projects/{project}/reusable-assets          201 + 单个 item；同名 → 422
 *   PATCH /api/projects/{project}/reusable-assets/{asset}  200 + 更新后 item；显式 null = 清空
 *
 * 不提供 DELETE；不允许 rename / change type；last_used_at 是系统字段（GET/POST/PATCH 都不动它）。
 */
class ReusableAssetManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(Project $project): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $project->id]);
    }

    /** @return list<string> */
    private function itemKeys(): array
    {
        return ['id', 'type', 'name', 'status', 'thumbnail_url', 'description', 'last_used_at'];
    }

    // ── GET 契约冻结 ──────────────────────────────────────────────

    public function test_get_contract_unchanged(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        ReusableAsset::factory()->for($project)->scene()->missing()->create(['name' => '厨房']);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();
        $payload = $response->json();

        $this->assertSame(['characters', 'scenes'], array_keys($payload), 'GET 顶层仍是 characters / scenes，不得包 data');
        $this->assertArrayNotHasKey('data', $payload);
        $this->assertSame($this->itemKeys(), array_keys($payload['characters'][0]));
        $this->assertSame($this->itemKeys(), array_keys($payload['scenes'][0]));
    }

    public function test_get_requires_auth_and_matching_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertUnauthorized();

        $this->acting($project);
        $this->getJson("/api/projects/{$other->id}/reusable-assets")->assertNotFound();
    }

    // ── POST 创建 ────────────────────────────────────────────────

    public function test_create_character_returns_201_with_item(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $response = $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character',
            'name' => '妈妈',
            'status' => 'missing',
            'thumbnail_url' => 'https://cdn.test/mom.png',
            'description' => '固定身份',
        ])->assertCreated();

        $payload = $response->json();
        $this->assertSame($this->itemKeys(), array_keys($payload));
        $this->assertSame('character', $payload['type']);
        $this->assertSame('妈妈', $payload['name']);
        $this->assertSame('missing', $payload['status']);
        $this->assertSame('https://cdn.test/mom.png', $payload['thumbnail_url']);
        $this->assertSame('固定身份', $payload['description']);
        $this->assertNull($payload['last_used_at'], 'create 不得写 last_used_at');

        $this->assertDatabaseHas('reusable_assets', [
            'project_id' => $project->id,
            'type' => 'character',
            'name' => '妈妈',
            'status' => 'missing',
        ]);
    }

    public function test_create_scene_returns_201_with_item(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $payload = $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'scene',
            'name' => '客厅',
            'status' => 'existing',
        ])->assertCreated()->json();

        $this->assertSame($this->itemKeys(), array_keys($payload));
        $this->assertSame('scene', $payload['type']);
        $this->assertSame('existing', $payload['status']);
        $this->assertNull($payload['thumbnail_url']);
        $this->assertNull($payload['description']);
    }

    public function test_create_trims_name(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $payload = $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character',
            'name' => '  妈妈  ',
            'status' => 'existing',
        ])->assertCreated()->json();

        $this->assertSame('妈妈', $payload['name']);
    }

    public function test_duplicate_type_name_returns_422_and_does_not_update_existing(): void
    {
        $project = Project::factory()->create();
        $existing = ReusableAsset::factory()->for($project)->character()->missing()->create([
            'name' => '妈妈',
            'description' => '原始说明',
        ]);
        $this->acting($project);

        $response = $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character',
            'name' => '妈妈',
            'status' => 'existing',
            'description' => '不该写入',
        ])->assertStatus(422);

        $this->assertStringContainsString('同名人物已存在', (string) $response->json('message').implode(' ', $response->json('errors.name') ?? []));
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $project->id)->count());
        $this->assertSame('missing', $existing->fresh()->status->value);
        $this->assertSame('原始说明', $existing->fresh()->description);
    }

    public function test_duplicate_scene_returns_422_with_scene_message(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->scene()->existing()->create(['name' => '厨房']);
        $this->acting($project);

        $response = $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'scene',
            'name' => '厨房',
            'status' => 'missing',
        ])->assertStatus(422);

        $errors = $response->json('errors.name');
        $this->assertIsArray($errors);
        $this->assertStringContainsString('同名场景已存在', (string) $errors[0]);
    }

    public function test_same_name_across_types_is_allowed(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '办公室', 'status' => 'missing',
        ])->assertCreated();

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'scene', 'name' => '办公室', 'status' => 'missing',
        ])->assertCreated();

        $this->assertSame(2, ReusableAsset::query()->where('project_id', $project->id)->count());
    }

    public function test_invalid_type_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'prop', 'name' => '椅子', 'status' => 'existing',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_invalid_status_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '妈妈', 'status' => 'draft',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_status_is_required_on_create(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '妈妈',
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_empty_or_whitespace_name_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '', 'status' => 'existing',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '     ', 'status' => 'existing',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame(0, ReusableAsset::query()->count());
    }

    public function test_name_too_long_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => str_repeat('长', 201), 'status' => 'existing',
        ])->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_forged_project_id_returns_422(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '妈妈', 'status' => 'existing',
            'project_id' => $other->id,
        ])->assertStatus(422)->assertJsonValidationErrors('project_id');

        $this->assertSame(0, ReusableAsset::query()->count());
    }

    public function test_forged_last_used_at_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'character', 'name' => '妈妈', 'status' => 'existing',
            'last_used_at' => '2026-10-16T08:00:00Z',
        ])->assertStatus(422)->assertJsonValidationErrors('last_used_at');

        $this->assertSame(0, ReusableAsset::query()->count());
    }

    public function test_forged_id_and_timestamps_return_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        foreach (['id', 'created_at', 'updated_at'] as $field) {
            $this->postJson("/api/projects/{$project->id}/reusable-assets", [
                'type' => 'character', 'name' => '妈妈', 'status' => 'existing', $field => 1,
            ])->assertStatus(422)->assertJsonValidationErrors($field);
        }
    }

    public function test_unsafe_thumbnail_scheme_returns_422(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,AAAA', 'file:///etc/passwd', 'ftp://x/y.png'] as $url) {
            $this->postJson("/api/projects/{$project->id}/reusable-assets", [
                'type' => 'character', 'name' => '妈妈', 'status' => 'existing', 'thumbnail_url' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('thumbnail_url');
        }

        $this->assertSame(0, ReusableAsset::query()->count());
    }

    public function test_safe_thumbnail_urls_are_accepted(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        foreach (['https://cdn.test/a.png', 'http://cdn.test/b.png', '/storage/c.png'] as $i => $url) {
            $this->postJson("/api/projects/{$project->id}/reusable-assets", [
                'type' => 'character', 'name' => "妈妈{$i}", 'status' => 'existing', 'thumbnail_url' => $url,
            ])->assertCreated();
        }

        $this->assertSame(3, ReusableAsset::query()->count());
    }

    public function test_create_requires_auth_and_matching_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();

        $body = ['type' => 'character', 'name' => '妈妈', 'status' => 'existing'];

        $this->postJson("/api/projects/{$project->id}/reusable-assets", $body)->assertUnauthorized();

        $this->acting($project);
        $this->postJson("/api/projects/{$other->id}/reusable-assets", $body)->assertNotFound();

        $this->assertSame(0, ReusableAsset::query()->count());
    }

    // ── PATCH 编辑 ───────────────────────────────────────────────

    public function test_patch_status(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->missing()->create(['name' => '妈妈']);
        $this->acting($project);

        $payload = $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'status' => 'existing',
        ])->assertOk()->json();

        $this->assertSame($this->itemKeys(), array_keys($payload));
        $this->assertSame('existing', $payload['status']);
        $this->assertSame('existing', $asset->fresh()->status->value);
    }

    public function test_patch_thumbnail_url(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->scene()->existing()->create(['name' => '客厅']);
        $this->acting($project);

        $payload = $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'thumbnail_url' => 'https://cdn.test/living.png',
        ])->assertOk()->json();

        $this->assertSame('https://cdn.test/living.png', $payload['thumbnail_url']);
    }

    public function test_patch_description(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->scene()->existing()->create(['name' => '厨房']);
        $this->acting($project);

        $payload = $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'description' => '稳定母版说明',
        ])->assertOk()->json();

        $this->assertSame('稳定母版说明', $payload['description']);
    }

    public function test_patch_explicit_null_clears_thumbnail(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->withThumbnail()->create(['name' => '妈妈']);
        $this->acting($project);

        $payload = $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'thumbnail_url' => null,
        ])->assertOk()->json();

        $this->assertNull($payload['thumbnail_url']);
        $this->assertNull($asset->fresh()->thumbnail_url);
    }

    public function test_patch_explicit_null_clears_description(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create([
            'name' => '妈妈',
            'description' => '旧说明',
        ]);
        $this->acting($project);

        $payload = $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'description' => null,
        ])->assertOk()->json();

        $this->assertNull($payload['description']);
        $this->assertNull($asset->fresh()->description);
    }

    public function test_patch_name_returns_422(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        $this->acting($project);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'name' => '爸爸',
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertSame('妈妈', $asset->fresh()->name, 'name 是稳定身份，不可改');
    }

    public function test_patch_type_returns_422(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        $this->acting($project);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'type' => 'scene',
        ])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame('character', $asset->fresh()->type->value, 'type 是稳定身份，不可改');
    }

    public function test_patch_forged_id_project_id_last_used_at_return_422(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        $this->acting($project);

        foreach (['id' => 999, 'project_id' => $other->id, 'last_used_at' => '2026-10-16T08:00:00Z'] as $field => $value) {
            $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        $this->assertNull($asset->fresh()->last_used_at, 'last_used_at 不得被客户端写入');
    }

    public function test_patch_invalid_status_and_unsafe_thumbnail_return_422(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        $this->acting($project);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", ['status' => 'archived'])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", ['thumbnail_url' => 'data:image/png;base64,AAAA'])
            ->assertStatus(422)->assertJsonValidationErrors('thumbnail_url');

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", ['status' => null])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_patch_cross_project_asset_returns_404(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $foreign = ReusableAsset::factory()->for($other)->character()->existing()->create(['name' => '别人家的妈妈']);
        $this->acting($project);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$foreign->id}", [
            'status' => 'missing',
        ])->assertNotFound();

        $this->assertSame('existing', $foreign->fresh()->status->value, '跨 Project 不得被修改');
    }

    public function test_patch_unknown_asset_returns_404(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/999999", ['status' => 'existing'])
            ->assertNotFound();
    }

    public function test_patch_requires_auth(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", ['status' => 'missing'])
            ->assertUnauthorized();
    }

    // ── last_used_at 无副作用 ────────────────────────────────────

    public function test_get_post_patch_do_not_touch_last_used_at(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create([
            'name' => '妈妈',
            'last_used_at' => '2026-10-01 09:00:00',
        ]);
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->postJson("/api/projects/{$project->id}/reusable-assets", [
            'type' => 'scene', 'name' => '客厅', 'status' => 'missing',
        ])->assertCreated();

        $this->patchJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}", [
            'status' => 'missing', 'description' => '改了说明',
        ])->assertOk();

        $this->assertSame('2026-10-01 09:00:00', $asset->fresh()->last_used_at->toDateTimeString());
    }

    // ── 没有 DELETE ─────────────────────────────────────────────

    public function test_no_delete_route_exists(): void
    {
        $project = Project::factory()->create();
        $asset = ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '妈妈']);
        $this->acting($project);

        $this->deleteJson("/api/projects/{$project->id}/reusable-assets/{$asset->id}")->assertStatus(405);
        $this->assertNull($asset->fresh()->deleted_at ?? null);
        $this->assertSame(1, ReusableAsset::query()->where('project_id', $project->id)->count());
    }

    // ── 枚举 / 展示层不变 ───────────────────────────────────────

    public function test_status_and_type_enums_unchanged(): void
    {
        $this->assertSame(['character', 'scene'], array_map(fn ($t) => $t->value, ReusableAssetType::cases()));
        $this->assertSame(['existing', 'missing'], array_map(fn ($s) => $s->value, ReusableAssetStatus::cases()));
    }
}
