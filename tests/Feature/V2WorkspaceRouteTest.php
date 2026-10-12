<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class V2WorkspaceRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_requires_auth_and_project_context(): void
    {
        $project = Project::factory()->create();
        foreach (['in-progress' => 'InProgress/Index', 'inspirations' => 'Inspirations/Index', 'topics' => 'Topics/Pool', 'content' => 'ContentLibrary/Index', 'review' => 'ReviewInbox/Index'] as $path => $component) {
            $url = '/projects/'.$project->id.'/'.$path;
            $this->get($url)->assertRedirect('/auth/login');
            $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $project->id]);
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component)->where('projectId', $project->id));
            auth()->forgetGuards();
        }
    }
}
