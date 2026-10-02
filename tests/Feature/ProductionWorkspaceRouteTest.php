<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * DEV-W06 — Production + Channel 工作台页面路由。
 *
 * 该路由只是 Inertia 页面入口：只透传 id，不在服务端查询任何业务数据，
 * 真实数据一律由前端调用 DEV-009A / DEV-W05 的正式 API 获取。
 */
class ProductionWorkspaceRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_workspace_renders_with_scope_props(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->get("/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Production/Workspace')
                ->where('projectId', $project->id)
                ->where('columnId', $column->id)
                ->where('topicId', $topic->id)
                ->where('itemId', $item->id)
            );
    }

    public function test_production_page_route_is_distinct_from_the_api_prefix(): void
    {
        // 页面路由不带 /api 前缀，且与既有 /copy 入口互不影响。
        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(fn ($route) => $route->getName() === 'items.production')
        );
        $url = route('items.production', [
            'project' => 1, 'column' => 2, 'topic' => 3, 'item' => 4,
        ], false);
        $this->assertStringStartsWith('/projects/', $url);
        $this->assertStringNotContainsString('/api/', $url);
    }
}
