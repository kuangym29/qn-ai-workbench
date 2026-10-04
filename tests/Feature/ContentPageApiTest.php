<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

class ContentPageApiTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id,
        ]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        return [$project, $column, $topic, $item,
            "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}"];
    }

    private function page(ContentItem $item, int $number, string $type = 'content'): ContentPage
    {
        return ContentPage::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'page_no' => $number, 'page_type' => $type,
        ]);
    }

    public function test_page_list_create_show_update_and_validation(): void
    {
        [$project, , , $item, $base] = $this->context();
        $one = $this->page($item, 1);
        $this->getJson("$base/pages")->assertOk()->assertJsonPath('data.0.id', $one->id)
            ->assertJsonPath('data.0.latest_version', null);
        $created = $this->postJson("$base/pages", ['page_no' => 2, 'page_type' => 'cover'])
            ->assertCreated()->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.content_item_id', $item->id)
            ->assertJsonPath('data.page_type', 'cover');
        $id = $created->json('data.id');
        $this->getJson("$base/pages/$id")->assertOk()->assertJsonPath('data.page_no', 2);
        $this->patchJson("$base/pages/$id", ['page_type' => 'column_closing'])
            ->assertOk()->assertJsonPath('data.page_type', 'column_closing');
        $this->patchJson("$base/pages/$id", ['page_no' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('page_no');
        $this->postJson("$base/pages", ['page_no' => 0, 'page_type' => 'content'])
            ->assertUnprocessable()->assertJsonValidationErrors('page_no');
        $this->postJson("$base/pages", ['page_no' => 3, 'page_type' => 'bad'])
            ->assertUnprocessable()->assertJsonValidationErrors('page_type');
        $this->postJson("$base/pages", ['page_no' => 2, 'page_type' => 'content'])
            ->assertUnprocessable()->assertJsonValidationErrors('page_no');
        foreach (['project_id', 'content_item_id', 'topic_id', 'content_column_id'] as $key) {
            $this->postJson("$base/pages", ['page_no' => 3, 'page_type' => 'content', $key => 999])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('content_pages', 2);
    }

    public function test_drafts_append_inherit_fields_and_working_returns_latest(): void
    {
        [, , , $item, $base] = $this->context();
        $page = $this->page($item, 1);
        $empty = $this->page($item, 2);
        $this->getJson("$base/copy/working")->assertOk()->assertJsonPath('data.1.latest_version', null);
        $first = $this->postJson("$base/pages/{$page->id}/drafts", [
            'page_title' => '第一稿', 'page_small_text' => '说明',
        ])->assertCreated()->assertJsonPath('data.version_no', 1)
            ->assertJsonPath('data.copy_revision_id', null);
        $second = $this->postJson("$base/pages/{$page->id}/drafts", ['page_title' => '第二稿'])
            ->assertCreated()->assertJsonPath('data.version_no', 2)
            ->assertJsonPath('data.page_small_text', '说明');
        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->getJson("$base/copy/working")->assertOk()
            ->assertJsonPath('data.0.latest_version.page_title', '第二稿')
            ->assertJsonPath('data.1.id', $empty->id);
        $this->getJson("$base/pages")->assertOk()->assertJsonPath('data.0.latest_version.version_no', 2);
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'copy_status' => 'editing']);
        foreach (['version_no', 'copy_revision_id', 'page_no_snapshot', 'page_type_snapshot', 'unknown'] as $field) {
            $this->postJson("$base/pages/{$page->id}/drafts", [$field => 1])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_reorder_requires_exact_pages_and_keeps_continuous_numbers(): void
    {
        [, , , $item, $base] = $this->context();
        $a = $this->page($item, 1);
        $b = $this->page($item, 2);
        $c = $this->page($item, 3);
        $foreign = ContentPage::factory()->create();
        $url = "$base/pages/reorder";
        $this->postJson($url, ['page_ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.2.page_no', 3);
        $this->assertSame([1, 2, 3], $item->contentPages()->orderBy('page_no')->pluck('page_no')->all());
        $this->postJson($url, ['page_ids' => [$a->id, $a->id, $b->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('page_ids.1');
        $this->postJson($url, ['page_ids' => [$a->id, $b->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('page_ids');
        $this->postJson($url, ['page_ids' => [$a->id, $b->id, $foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('page_ids');
    }

    public function test_confirm_revisions_current_and_working_are_distinct(): void
    {
        [, , , $item, $base] = $this->context();
        $cover = $this->page($item, 1, 'cover');
        $body = $this->page($item, 2);
        $this->getJson("$base/copy/current")->assertOk()->assertJsonPath('data', null);
        $this->postJson("$base/copy/confirm")->assertUnprocessable()->assertJsonValidationErrors('copy');
        $this->assertDatabaseCount('content_copy_revisions', 0);
        $this->postJson("$base/pages/{$cover->id}/drafts", ['cover_title' => '旧封面'])->assertCreated();
        $this->postJson("$base/pages/{$body->id}/drafts", ['page_title' => '正文'])->assertCreated();
        $one = $this->postJson("$base/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 1)->assertJsonCount(2, 'data.page_versions');
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'copy_status' => 'confirmed']);
        $this->postJson("$base/pages/{$cover->id}/drafts", ['cover_title' => '新封面'])->assertCreated();
        $this->getJson("$base/copy/working")->assertOk()
            ->assertJsonPath('data.0.latest_version.cover_title', '新封面')
            ->assertJsonPath('data.1.latest_version.page_title', '正文');
        $this->getJson("$base/copy/current")->assertOk()
            ->assertJsonPath('data.revision_no', 1)
            ->assertJsonPath('data.page_versions.0.cover_title', '旧封面');
        $two = $this->postJson("$base/copy/confirm")->assertCreated()
            ->assertJsonPath('data.revision_no', 2)->assertJsonCount(2, 'data.page_versions');
        $this->assertNotSame($one->json('data.id'), $two->json('data.id'));
        $this->getJson("$base/copy/revisions")->assertOk()
            ->assertJsonPath('data.0.revision_no', 2)->assertJsonPath('data.1.revision_no', 1);
        $this->getJson("$base/copy/revisions/{$one->json('data.id')}")->assertOk()
            ->assertJsonPath('data.page_versions.0.cover_title', '旧封面');
        $this->getJson("$base/copy/current")->assertOk()->assertJsonPath('data.revision_no', 2);
        $this->assertDatabaseCount('content_page_versions', 7);
    }

    public function test_scope_rejects_every_wrong_ancestor_page_and_revision(): void
    {
        [$project, $column, $topic, $item, $base] = $this->context();
        $page = $this->page($item, 1);
        $this->postJson("$base/pages/{$page->id}/drafts", ['page_title' => '正文'])->assertCreated();
        $revision = $this->postJson("$base/copy/confirm")->assertCreated()->json('data.id');
        $otherProject = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($project)->create();
        $otherTopic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $otherItem = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $otherPage = $this->page($otherItem, 1);
        $wrongProject = str_replace("/projects/{$project->id}/", "/projects/{$otherProject->id}/", $base);
        $wrongColumn = str_replace("/columns/{$column->id}/", "/columns/{$otherColumn->id}/", $base);
        $wrongTopic = str_replace("/topics/{$topic->id}/", "/topics/{$otherTopic->id}/", $base);
        $wrongItem = str_replace("/items/{$item->id}", "/items/{$otherItem->id}", $base);
        foreach ([$wrongProject, $wrongColumn, $wrongTopic] as $wrong) {
            $this->getJson("$wrong/pages")->assertNotFound();
            $this->postJson("$wrong/pages", ['page_no' => 2, 'page_type' => 'content'])->assertNotFound();
            $this->postJson("$wrong/pages", ['page_no' => 0, 'page_type' => 'bad'])->assertNotFound();
            $this->getJson("$wrong/copy/current")->assertNotFound();
        }
        $this->getJson("$wrongItem/pages/{$page->id}")->assertNotFound();
        $this->patchJson("$wrongItem/pages/{$page->id}", ['page_type' => 'cover'])->assertNotFound();
        $this->postJson("$wrongItem/pages/{$page->id}/drafts", ['page_title' => '非法'])->assertNotFound();
        $this->getJson("$base/pages/{$otherPage->id}")->assertNotFound();
        $this->getJson("$wrongItem/copy/revisions/$revision")->assertNotFound();
        $this->getJson("$base/copy/revisions/999999")->assertNotFound();
    }

    public function test_invalid_copy_confirm_returns_422_and_rolls_back(): void
    {
        [, , , $item, $base] = $this->context();
        $page = $this->page($item, 1, 'cover');
        $this->postJson("$base/pages/{$page->id}/drafts", ['page_title' => '错误字段'])->assertCreated();
        $this->postJson("$base/copy/confirm")->assertUnprocessable()->assertJsonValidationErrors('copy');
        $this->assertDatabaseCount('content_copy_revisions', 0);
        $this->assertDatabaseCount('content_page_versions', 1);
    }
}
