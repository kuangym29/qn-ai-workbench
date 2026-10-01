<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_can_be_listed_created_and_edited_in_the_selected_context(): void
    {
        $this->getJson('/api/projects')->assertOk()->assertJsonPath('data', []);

        $created = $this->postJson('/api/projects', [
            'name' => '青柠育见', 'slug' => 'qingning-yujian', 'description' => '教育内容',
        ])->assertCreated()->assertJsonPath('data.name', '青柠育见');

        $id = $created->json('data.id');
        $this->getJson('/api/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data', null);
        $this->postJson("/api/projects/{$id}/select")->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/api/projects/current')->assertOk()->assertJsonPath('data.id', $id);

        $this->patchJson("/api/projects/{$id}", ['name' => '青柠育见新版'])
            ->assertOk()->assertJsonPath('data.name', '青柠育见新版');
        $this->assertDatabaseHas('projects', ['id' => $id, 'name' => '青柠育见新版']);
    }

    public function test_columns_are_listed_created_and_edited_only_under_the_current_project(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $ownColumn = ContentColumn::factory()->for($project)->create(['sort_order' => 2]);
        ContentColumn::factory()->for($other)->create();

        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->getJson("/api/projects/{$project->id}/columns")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ownColumn->id);

        $created = $this->postJson("/api/projects/{$project->id}/columns", [
            'name' => '家庭教育', 'slug' => 'family', 'sort_order' => 1,
        ])->assertCreated()->assertJsonPath('data.project_id', $project->id);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('content_columns', ['id' => $id, 'project_id' => $project->id]);

        $this->getJson("/api/projects/{$project->id}/columns/{$id}")
            ->assertOk()->assertJsonPath('data.name', '家庭教育');
        $this->patchJson("/api/projects/{$project->id}/columns/{$id}", ['name' => '家庭成长'])
            ->assertOk()->assertJsonPath('data.name', '家庭成长');
    }

    public function test_cross_project_column_reads_and_writes_are_rejected(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $foreignColumn = ContentColumn::factory()->for($other)->create();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->getJson("/api/projects/{$other->id}/columns")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/columns/{$foreignColumn->id}")->assertNotFound();
        $this->patchJson("/api/projects/{$project->id}/columns/{$foreignColumn->id}", ['name' => '非法修改'])
            ->assertNotFound();
        $this->patchJson("/api/projects/{$other->id}/columns/{$foreignColumn->id}", ['name' => '非法修改'])
            ->assertNotFound();
        $this->assertDatabaseHas('content_columns', ['id' => $foreignColumn->id, 'name' => $foreignColumn->name]);
    }

    public function test_client_project_id_cannot_override_server_context(): void
    {
        $project = Project::factory()->create();
        $other = Project::factory()->create();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->postJson("/api/projects/{$project->id}/columns", [
            'name' => '安全栏目', 'slug' => 'safe', 'project_id' => $other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson("/api/projects/{$other->id}/columns", [
            'name' => '安全栏目', 'slug' => 'safe', 'project_id' => $other->id,
        ])->assertNotFound();
        $this->patchJson("/api/projects/{$other->id}", ['name' => '篡改'])
            ->assertNotFound();
        $this->assertDatabaseCount('content_columns', 0);
    }

    public function test_invalid_project_and_column_inputs_are_rejected(): void
    {
        $project = Project::factory()->create();
        $this->postJson('/api/projects', ['name' => '', 'slug' => 'Invalid Slug'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug']);
        $this->postJson('/api/projects', ['name' => '重复', 'slug' => $project->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->postJson("/api/projects/{$project->id}/columns", ['name' => '', 'slug' => 'bad slug'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'slug']);
        $column = ContentColumn::factory()->for($project)->create();
        $this->postJson("/api/projects/{$project->id}/columns", ['name' => '重复', 'slug' => $column->slug])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->patchJson("/api/projects/{$project->id}/columns/{$column->id}", ['sort_order' => -1])
            ->assertUnprocessable()->assertJsonValidationErrors('sort_order');
    }

    public function test_inertia_shares_only_the_selected_project_context(): void
    {
        $project = Project::factory()->create();
        Project::factory()->create();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bootstrap')
            ->where('currentProject', null));

        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Bootstrap')
            ->where('currentProject.id', $project->id)
            ->where('currentProject.slug', $project->slug));
    }
}
