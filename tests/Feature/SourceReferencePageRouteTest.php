<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

/**
 * DEV-W07 — 来源管理页面路由。
 *
 * 两个页面路由都不带 /api 前缀，与 DEV-W07 的 JSON API 完全不冲突。
 */
class SourceReferencePageRouteTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    public function test_project_sources_page_renders_with_project_prop(): void
    {
        $project = Project::factory()->create();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->get("/projects/{$project->id}/sources")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sources/ProjectSources')
                ->where('projectId', $project->id)
            );
    }

    public function test_item_sources_page_renders_with_scope_props(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->get("/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/sources")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Sources/ItemSources')
                ->where('projectId', $project->id)
                ->where('columnId', $column->id)
                ->where('topicId', $topic->id)
                ->where('itemId', $item->id)
            );
    }

    public function test_page_routes_are_named_and_do_not_collide_with_the_api(): void
    {
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(fn ($route) => $route->getName() === 'sources.project')
        );
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(fn ($route) => $route->getName() === 'sources.item')
        );

        $projectUrl = route('sources.project', ['project' => 1], false);
        $itemUrl = route('sources.item', [
            'project' => 1, 'column' => 2, 'topic' => 3, 'item' => 4,
        ], false);

        // 页面路由不带 /api 前缀
        $this->assertStringStartsWith('/projects/', $projectUrl);
        $this->assertStringStartsWith('/projects/', $itemUrl);
        $this->assertStringNotContainsString('/api/', $projectUrl);
        $this->assertStringNotContainsString('/api/', $itemUrl);
    }
}
