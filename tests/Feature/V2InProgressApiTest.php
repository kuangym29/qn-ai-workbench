<?php

namespace Tests\Feature;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2InProgressApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_aggregates_active_formats_and_derives_next_action(): void
    {
        $item = ContentItem::factory()->create();
        foreach (['image_post', 'article'] as $format) {
            ContentBranch::factory()->for($item)->create(['format' => $format]);
        }
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $base = '/api/projects/'.$item->project_id;
        $this->getJson($base.'/in-progress')->assertOk()->assertJsonCount(1, 'data')->assertJsonCount(2, 'data.0.active_branches')->assertJsonPath('data.0.next_action', '完善内容');
        $this->patchJson($base.'/items/'.$item->id, ['core_message' => 'Message', 'story_line' => 'Story'])->assertOk();
        $this->getJson($base.'/in-progress')->assertJsonPath('data.0.next_action', '开始制作');
        foreach ($item->contentBranches as $branch) {
            $branch->stop();
        }
        $this->getJson($base.'/in-progress')->assertJsonCount(0, 'data.0.active_branches')->assertJsonPath('data.0.next_action', '添加制作渠道');
        $this->getJson($base.'/items/'.$item->id)->assertJsonCount(2, 'data.content_branches');
    }
}
