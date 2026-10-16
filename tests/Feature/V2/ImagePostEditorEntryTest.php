<?php

namespace Tests\Feature\V2;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImagePostEditorEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_entry_passes_the_scoped_branch_and_real_source(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'image_post']);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);

        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/image-post")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('V2/ImagePostEditor')
                ->where('projectId', $item->project_id)->where('itemId', $item->id)
                ->where('branchId', $branch->id)->where('columnName', $item->contentColumn->name)
                ->where('source.topic.id', $item->topic_id));
    }

    public function test_wrong_project_item_and_format_cannot_open_the_editor(): void
    {
        $item = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'article']);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $this->get("/projects/{$foreign->project_id}/content/{$item->id}/branches/{$branch->id}/image-post")->assertNotFound();
        $this->get("/projects/{$item->project_id}/content/{$foreign->id}/branches/{$branch->id}/image-post")->assertNotFound();
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/image-post")->assertUnprocessable();
    }

    public function test_development_entries_are_removed(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get('/projects/1/v2/image-post-preview')->assertNotFound();
        $this->get('/projects/1/v2/image-post/1')->assertNotFound();
    }
}
