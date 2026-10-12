<?php

namespace Tests\Feature;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2StartCreationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_topic_and_creation_response_contract(): void
    {
        $topic = Topic::factory()->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $topic->project_id]);
        $base = '/api/projects/'.$topic->project_id;
        $this->getJson($base.'/topics')->assertOk()->assertJsonPath('data.0.has_content_item', false)->assertJsonPath('data.0.content_item_id', null);
        $item = $this->postJson($base.'/topics/'.$topic->id.'/start', ['branches' => [['channel' => 'wechat_official', 'format' => 'image_post']]])->assertCreated()->assertJsonPath('data.content_branches.0.channel_label', '微信公众号')->assertJsonPath('data.content_branches.0.format_label', '图片文章（小绿书）')->json('data.id');
        $this->getJson($base.'/topics')->assertJsonPath('data.0.has_content_item', true)->assertJsonPath('data.0.content_item_id', $item);
    }
}
