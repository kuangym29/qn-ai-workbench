<?php

namespace Tests\Feature\V2;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

/**
 * 正式页面路由 GET /projects/{project}/reusable-assets（I06A）。
 *
 * 只是 Inertia 入口：只透传 projectId，不绕开 ProjectContext；
 * 索引数据由前端调用 GET /api/projects/{project}/reusable-assets 拉取。
 *
 * 权限：登录 + 当前 Project；跨 Project → 404；未登录 → 重定向登录。
 */
class ReusableAssetPageTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    private function selectProject(Project $project): void
    {
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
    }

    public function test_page_renders_reusable_asset_index_with_project_id(): void
    {
        $project = Project::factory()->create();
        $this->selectProject($project);

        $this->get("/projects/{$project->id}/reusable-assets")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('V2/ReusableAssets/ReusableAssetIndex')
                ->where('projectId', $project->id)
                ->has('projectId')
            );
    }

    public function test_page_does_not_leak_another_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->selectProject($project);

        $this->get("/projects/{$other->id}/reusable-assets")->assertNotFound();
    }

    public function test_page_requires_authentication(): void
    {
        // AuthenticatesUser trait 已在 setUp 登录；这里显式退出以验证未登录行为。
        auth()->logout();
        $project = Project::factory()->create();

        // 未登录访问页面：重定向登录页（web 守卫），而不是进入工作台。
        $this->get("/projects/{$project->id}/reusable-assets")->assertRedirect('/auth/login');
    }

    public function test_page_route_is_distinct_from_the_api_endpoint(): void
    {
        $routes = collect(app('router')->getRoutes());

        $pageRoute = $routes->first(fn ($route) => $route->uri() === 'projects/{project}/reusable-assets');
        $this->assertNotNull($pageRoute, '必须存在正式页面路由 projects/{project}/reusable-assets');
        $this->assertStringNotContainsString('api', $pageRoute->uri());

        $apiRoute = $routes->first(fn ($route) => $route->uri() === 'api/projects/{project}/reusable-assets');
        $this->assertNotNull($apiRoute, '必须存在只读 API 路由 api/projects/{project}/reusable-assets');
    }
}
