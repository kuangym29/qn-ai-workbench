<?php

namespace Tests\Feature;

use App\Enums\CopyStatus;
use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

class TopicContentItemApiTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        return [$project, $column];
    }

    public function test_topic_crud_and_server_owned_keys(): void
    {
        [$project, $column] = $this->context();
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics";
        $this->getJson($url)->assertOk()->assertJsonPath('data', []);
        $created = $this->postJson($url, ['title' => '选题', 'description' => null])
            ->assertCreated()->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.content_column_id', $column->id)->assertJsonPath('data.description', null);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('topics', ['id' => $id, 'project_id' => $project->id, 'content_column_id' => $column->id]);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("$url/$id")->assertOk()->assertJsonPath('data.title', '选题');
        $this->patchJson("$url/$id", ['title' => '新选题'])->assertOk()->assertJsonPath('data.title', '新选题');
    }

    public function test_topic_scope_and_validation(): void
    {
        [$project, $column] = $this->context();
        $otherProject = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($otherProject)->create();
        $sibling = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics";
        $this->getJson("/api/projects/{$otherProject->id}/columns/{$otherColumn->id}/topics")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/columns/{$otherColumn->id}/topics")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/columns/{$sibling->id}/topics/{$topic->id}")->assertNotFound();
        $this->patchJson("/api/projects/{$project->id}/columns/{$sibling->id}/topics/{$topic->id}", ['title' => '非法'])->assertNotFound();
        $this->postJson($url, ['title' => 'x', 'project_id' => $otherProject->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson($url, ['title' => 'x', 'content_column_id' => $sibling->id])->assertUnprocessable()->assertJsonValidationErrors('content_column_id');
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($url, ['title' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson("$url/{$topic->id}", ['description' => null])->assertOk()->assertJsonPath('data.description', null);
        $this->assertDatabaseCount('topics', 1);
    }

    public function test_item_crud_statuses_and_server_owned_keys(): void
    {
        [$project, $column] = $this->context();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items";
        $this->getJson($url)->assertOk()->assertJsonPath('data', []);
        $created = $this->postJson($url, ['title' => '篇目'])->assertCreated()
            ->assertJsonPath('data.copy_status', CopyStatus::NotStarted->value)
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.content_column_id', $column->id)
            ->assertJsonPath('data.topic_id', $topic->id);
        $id = $created->json('data.id');
        $this->assertDatabaseHas('content_items', ['id' => $id, 'project_id' => $project->id,
            'content_column_id' => $column->id, 'topic_id' => $topic->id, 'copy_status' => 'not_started']);
        $this->assertDatabaseCount('production_tasks', 0);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson("$url/$id")->assertOk()->assertJsonPath('data.title', '篇目');
        foreach (['not_started', 'editing', 'pending_confirmation'] as $status) {
            $this->patchJson("$url/$id", ['copy_status' => $status])->assertOk()->assertJsonPath('data.copy_status', $status);
            $this->assertDatabaseHas('content_items', ['id' => $id, 'copy_status' => $status]);
        }
        foreach (['not_started', 'editing', 'pending_confirmation', 'confirmed'] as $status) {
            $this->postJson($url, ['title' => '禁止指定状态', 'copy_status' => $status])
                ->assertUnprocessable()->assertJsonValidationErrors('copy_status');
        }
        $this->postJson($url, ['title' => '禁止空状态', 'copy_status' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('copy_status');
        $this->patchJson("$url/$id", ['copy_status' => 'confirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('copy_status')
            ->assertSee('Use the copy confirmation endpoint to confirm content.');
        $this->assertDatabaseHas('content_items', ['id' => $id, 'copy_status' => 'pending_confirmation']);
        $this->assertDatabaseCount('content_copy_revisions', 0);
        $this->patchJson("$url/$id", ['title' => '新篇目'])->assertOk()->assertJsonPath('data.title', '新篇目');
    }

    public function test_item_scope_and_validation(): void
    {
        [$project, $column] = $this->context();
        $otherProject = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($otherProject)->create();
        $sibling = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $otherTopic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id]);
        $url = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items";
        $this->getJson("/api/projects/{$otherProject->id}/columns/{$otherColumn->id}/topics/{$topic->id}/items")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/columns/{$sibling->id}/topics/{$topic->id}/items")->assertNotFound();
        $this->getJson("/api/projects/{$project->id}/columns/{$column->id}/topics/{$otherTopic->id}/items/{$item->id}")->assertNotFound();
        $this->patchJson("/api/projects/{$project->id}/columns/{$column->id}/topics/{$otherTopic->id}/items/{$item->id}", ['title' => '非法'])->assertNotFound();
        foreach (['project_id' => $otherProject->id, 'content_column_id' => $sibling->id, 'topic_id' => $otherTopic->id] as $key => $value) {
            $this->postJson($url, ['title' => 'x', $key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
            $this->patchJson("$url/{$item->id}", [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($url, ['title' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson($url, ['title' => 'x', 'copy_status' => 'draft'])->assertUnprocessable()->assertJsonValidationErrors('copy_status');
        $this->patchJson("$url/{$item->id}", ['copy_status' => 'bad'])->assertUnprocessable()->assertJsonValidationErrors('copy_status');
        $this->assertDatabaseCount('content_items', 1);
    }

    public function test_confirmed_status_requires_the_copy_confirmation_endpoint(): void
    {
        [$project, $column] = $this->context();
        $topic = Topic::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id,
        ]);
        $base = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items";
        $itemId = $this->postJson($base, ['title' => '正式链路'])->assertCreated()->json('data.id');
        $itemUrl = "$base/$itemId";
        $this->patchJson($itemUrl, ['copy_status' => 'confirmed'])
            ->assertUnprocessable()->assertJsonValidationErrors('copy_status');
        $this->assertDatabaseHas('content_items', ['id' => $itemId, 'copy_status' => 'not_started']);
        $this->assertDatabaseCount('content_copy_revisions', 0);

        $pageId = $this->postJson("$itemUrl/pages", ['page_no' => 1, 'page_type' => 'content'])
            ->assertCreated()->json('data.id');
        $this->postJson("$itemUrl/pages/$pageId/drafts", ['page_title' => '正式文案'])
            ->assertCreated();
        $revision = $this->postJson("$itemUrl/copy/confirm")
            ->assertCreated()->assertJsonPath('data.revision_no', 1)
            ->assertJsonCount(1, 'data.page_versions');
        $this->assertDatabaseHas('content_items', ['id' => $itemId, 'copy_status' => 'confirmed']);
        $this->assertDatabaseHas('content_copy_revisions', [
            'id' => $revision->json('data.id'), 'content_item_id' => $itemId,
        ]);
        $this->assertDatabaseHas('content_page_versions', [
            'content_page_id' => $pageId,
            'copy_revision_id' => $revision->json('data.id'),
            'page_title' => '正式文案',
        ]);
        $this->assertDatabaseCount('content_copy_revisions', 1);
    }
}
