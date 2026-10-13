<?php

namespace Tests\Feature\V2;

use App\Models\Project;
use App\Models\ReusableAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/projects/{project}/reusable-assets 契约（对齐 C06 前端冻结形状）：
 *
 *   { characters: [...], scenes: [...] }
 *
 * 每项固定 7 个字段：
 *   { id, type, name, status, thumbnail_url, description, last_used_at }
 *
 * 权限：登录 + 当前 Project；跨 Project → 404；未登录 → 401。
 * 本阶段只有这一个 GET：没有公开 CRUD、没有后台管理、没有上传。
 */
class ReusableAssetApiTest extends TestCase
{
    use RefreshDatabase;

    private function acting(Project $project): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['current_project_id' => $project->id]);
    }

    public function test_empty_project_returns_empty_characters_and_scenes(): void
    {
        $project = Project::factory()->create();
        $this->acting($project);

        $this->getJson("/api/projects/{$project->id}/reusable-assets")
            ->assertOk()
            ->assertExactJson(['characters' => [], 'scenes' => []]);
    }

    public function test_index_returns_characters(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->character()->existing()->create([
            'name' => '柠檬熊',
            'thumbnail_url' => 'https://cdn.test/lemon.png',
            'description' => '品牌主形象',
        ]);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->assertCount(1, $response->json('characters'));
        $this->assertSame([], $response->json('scenes'));
        $response->assertJsonPath('characters.0.type', 'character');
        $response->assertJsonPath('characters.0.name', '柠檬熊');
        $response->assertJsonPath('characters.0.status', 'existing');
        $response->assertJsonPath('characters.0.thumbnail_url', 'https://cdn.test/lemon.png');
        $response->assertJsonPath('characters.0.description', '品牌主形象');
        $response->assertJsonPath('characters.0.last_used_at', null);
    }

    public function test_index_returns_scenes(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->scene()->missing()->create([
            'name' => '海边落日',
        ]);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->assertSame([], $response->json('characters'));
        $this->assertCount(1, $response->json('scenes'));
        $response->assertJsonPath('scenes.0.type', 'scene');
        $response->assertJsonPath('scenes.0.status', 'missing');
    }

    public function test_item_shape_has_exactly_the_contract_fields(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->character()->existing()->withThumbnail()->create([
            'name' => '柠檬熊',
            'description' => '品牌主形象',
            'last_used_at' => now()->subDay(),
        ]);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->assertSame(
            ['id', 'type', 'name', 'status', 'thumbnail_url', 'description', 'last_used_at'],
            array_keys($response->json('characters.0'))
        );
    }

    public function test_nullable_fields_are_returned_as_null(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->scene()->missing()->create(['name' => '雪山清晨']);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $response->assertJsonPath('scenes.0.thumbnail_url', null);
        $response->assertJsonPath('scenes.0.description', null);
        $response->assertJsonPath('scenes.0.last_used_at', null);
    }

    public function test_existing_entries_come_before_missing_then_sorted_by_name(): void
    {
        $project = Project::factory()->create();
        ReusableAsset::factory()->for($project)->character()->missing()->create(['name' => 'Alpha']);
        ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => 'Zeta']);
        ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => 'Beta']);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->assertSame(['Beta', 'Zeta', 'Alpha'], array_column($response->json('characters'), 'name'));
    }

    public function test_only_the_current_project_index_is_returned(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        ReusableAsset::factory()->for($project)->character()->existing()->create(['name' => '柠檬熊']);
        ReusableAsset::factory()->for($other)->character()->existing()->create(['name' => '别人的角色']);
        $this->acting($project);

        $response = $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertOk();

        $this->assertSame(['柠檬熊'], array_column($response->json('characters'), 'name'));
    }

    public function test_cross_project_access_returns_404(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->acting($project);

        $this->getJson("/api/projects/{$other->id}/reusable-assets")->assertNotFound();
    }

    public function test_authentication_required_returns_401(): void
    {
        $project = Project::factory()->create();

        $this->getJson("/api/projects/{$project->id}/reusable-assets")->assertUnauthorized();
    }
}
