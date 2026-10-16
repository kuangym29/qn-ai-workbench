<?php

namespace Tests\Feature\V2;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VideoEditorEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_video_entry_passes_scoped_branch_ids(): void
    {
        $item = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_channels', 'format' => 'video']);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/video")
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('V2/Video/VideoEditor')
                ->where('projectId', $item->project_id)->where('itemId', $item->id)->where('branchId', $branch->id));
    }

    public function test_video_entry_rejects_wrong_project_format_and_channel(): void
    {
        $item = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $branch = ContentBranch::factory()->for($item)->create(['channel' => 'wechat_official', 'format' => 'article']);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $this->get("/projects/{$foreign->project_id}/content/{$item->id}/branches/{$branch->id}/video")->assertNotFound();
        $this->get("/projects/{$item->project_id}/content/{$foreign->id}/branches/{$branch->id}/video")->assertNotFound();
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/video")->assertUnprocessable();
        $branch->update(['format' => 'video']);
        $this->get("/projects/{$item->project_id}/content/{$item->id}/branches/{$branch->id}/video")->assertUnprocessable();
    }
}
