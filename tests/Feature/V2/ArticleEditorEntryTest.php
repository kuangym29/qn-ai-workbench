<?php

namespace Tests\Feature\V2;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArticleEditorEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_editor_is_scoped_and_receives_real_source(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'article']);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/article")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('V2/Article/ArticleEditor')
                ->where('projectId', $item->project_id)->where('itemId', $item->id)->where('branchId', $branch->id)
                ->where('columnName', $item->contentColumn->name)->where('source.topic.id', $item->topic_id));
        $foreign = ContentItem::factory()->create();
        $this->get("/projects/{$foreign->project_id}/content/{$item->id}/branches/{$branch->id}/article")->assertNotFound();
        $this->get("/projects/{$item->project_id}/content/{$foreign->id}/branches/{$branch->id}/article")->assertNotFound();
        $branch->update(['format' => 'image_post']);
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/article")->assertUnprocessable();
    }
}
