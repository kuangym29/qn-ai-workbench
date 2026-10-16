<?php

namespace Tests\Feature;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Inspiration;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V2ContentFlowApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_topic_start_and_branch_history(): void
    {
        $topic = Topic::factory()->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $topic->project_id]);
        $base = '/api/projects/'.$topic->project_id;
        $inspiration = $this->postJson($base.'/inspirations', ['body' => 'Idea'])->assertCreated()->json('data.id');
        $this->patchJson($base.'/inspirations/'.$inspiration, ['body' => 'Better', 'note' => 'Note'])->assertOk()->assertJsonPath('data.note', 'Note');
        $direct = $this->postJson($base.'/topics', ['title' => 'Direct', 'content_column_id' => $topic->content_column_id])->assertCreated();
        $direct->assertJsonPath('data.inspiration', null)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.is_backup', false);
        $this->patchJson($base.'/topics/'.$topic->id, ['status' => 'parked', 'is_backup' => true, 'inspiration_id' => $inspiration])->assertOk()->assertJsonPath('data.inspiration.body', 'Better');
        $this->patchJson($base.'/topics/'.$topic->id, ['status' => 'backup'])->assertUnprocessable();
        $pairs = [['channel' => 'wechat_official', 'format' => 'image_post'], ['channel' => 'wechat_channels', 'format' => 'video']];
        $response = $this->postJson($base.'/topics/'.$topic->id.'/start', ['branches' => $pairs])->assertCreated()->assertJsonCount(2, 'data.content_branches');
        $item = $response->json('data.id');
        $this->postJson($base.'/topics/'.$topic->id.'/start', ['branches' => $pairs])->assertConflict()->assertJsonPath('content_item_id', $item);
        $branch = $this->postJson($base.'/items/'.$item.'/branches', ['channel' => 'wechat_official', 'format' => 'article'])->assertOk()->json('data.id');
        $this->postJson($base.'/items/'.$item.'/branches/'.$branch.'/stop')->assertOk();
        $this->getJson($base.'/items/'.$item)->assertJsonCount(3, 'data.content_branches');
        $this->postJson($base.'/items/'.$item.'/branches', ['channel' => 'wechat_official', 'format' => 'article'])->assertOk()->assertJsonPath('data.id', $branch)->assertJsonPath('data.stopped_at', null);
        $this->assertDatabaseCount('content_items', 1);
        $this->assertDatabaseCount('content_branches', 3);
    }

    public function test_invalid_pairs_are_atomic_and_foreign_ids_return_404_before_validation(): void
    {
        $topic = Topic::factory()->create();
        $foreign = Topic::factory()->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $topic->project_id]);
        $base = '/api/projects/'.$topic->project_id;
        foreach ([['channel' => 'wechat_channels', 'format' => 'article'], ['channel' => 'xiaohongshu', 'format' => 'image_post']] as $invalid) {
            $this->postJson($base.'/topics/'.$topic->id.'/start', ['branches' => [['channel' => 'wechat_official', 'format' => 'article'], $invalid]])->assertUnprocessable();
        }
        $this->postJson($base.'/topics/'.$foreign->id.'/start', [])->assertNotFound();
        $this->postJson($base.'/topics', ['content_column_id' => $foreign->content_column_id])->assertNotFound();
        $this->getJson('/api/projects/'.$foreign->project_id.'/topics')->assertNotFound();
        $this->assertDatabaseCount('content_items', 0);
        $this->assertDatabaseCount('content_branches', 0);
    }

    public function test_all_foreign_records_are_inaccessible_and_writes_are_scoped(): void
    {
        $item = ContentItem::factory()->create();
        $foreign = ContentItem::factory()->create();
        $foreignIdea = Inspiration::factory()->create();
        $branch = ContentBranch::factory()->for($foreign)->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $base = '/api/projects/'.$item->project_id;
        $this->patchJson($base.'/inspirations/'.$foreignIdea->id, [])->assertNotFound();
        $this->patchJson($base.'/topics/'.$foreign->topic_id, [])->assertNotFound();
        $this->patchJson($base.'/topics/'.$item->topic_id, ['inspiration_id' => $foreignIdea->id, 'status' => 'invalid'])->assertNotFound();
        $this->getJson($base.'/items/'.$foreign->id)->assertNotFound();
        $this->patchJson($base.'/items/'.$foreign->id, [])->assertNotFound();
        $this->postJson($base.'/items/'.$foreign->id.'/branches', [])->assertNotFound();
        $this->postJson($base.'/items/'.$item->id.'/branches/'.$branch->id.'/stop')->assertNotFound();
        $this->patchJson($base.'/items/'.$item->id, ['project_id' => $foreign->project_id, 'topic_id' => $foreign->topic_id, 'copy_status' => 'confirmed', 'title' => 'Shared'])->assertOk()->assertJsonPath('data.project_id', $item->project_id)->assertJsonPath('data.topic_id', $item->topic_id);
        $this->assertNull($branch->refresh()->stopped_at);
        $this->assertDatabaseCount('content_branches', 1);
    }

    public function test_authentication_is_required_for_every_v2_api(): void
    {
        $item = ContentItem::factory()->create();
        $base = '/api/projects/'.$item->project_id;
        foreach (['/inspirations', '/topics', '/items', '/items/'.$item->id, '/in-progress'] as $path) {
            $this->getJson($base.$path)->assertUnauthorized();
        }
        foreach (['/inspirations', '/topics', '/topics/'.$item->topic_id.'/start', '/items/'.$item->id.'/branches', '/items/'.$item->id.'/branches/1/stop'] as $path) {
            $this->postJson($base.$path, [])->assertUnauthorized();
        }
        foreach (['/inspirations/1', '/topics/'.$item->topic_id, '/items/'.$item->id] as $path) {
            $this->patchJson($base.$path, [])->assertUnauthorized();
        }
    }

    public function test_empty_or_duplicate_branch_choices_never_create_partial_content(): void
    {
        $topic = Topic::factory()->create();
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $topic->project_id]);
        $url = '/api/projects/'.$topic->project_id.'/topics/'.$topic->id.'/start';
        $pair = ['channel' => 'wechat_official', 'format' => 'article'];
        foreach ([[], [$pair, $pair], [['channel' => 'wechat_official']]] as $branches) {
            $this->postJson($url, ['branches' => $branches])->assertUnprocessable();
        }
        $this->assertDatabaseCount('content_items', 0);
        $this->assertDatabaseCount('content_branches', 0);
    }

    public function test_titles_follow_database_limit_and_started_topic_keeps_its_column(): void
    {
        $item = ContentItem::factory()->create();
        $otherTopic = Topic::factory()->create(['project_id' => $item->project_id]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $base = '/api/projects/'.$item->project_id;
        $longTitle = str_repeat('a', 201);
        $this->postJson($base.'/topics', ['title' => $longTitle, 'content_column_id' => $item->content_column_id])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson($base.'/topics/'.$item->topic_id, ['title' => $longTitle])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->patchJson($base.'/items/'.$item->id, ['title' => $longTitle])->assertUnprocessable()->assertJsonValidationErrors('title');
    }

    public function test_started_topic_cannot_move_to_another_column(): void
    {
        $item = ContentItem::factory()->create();
        $otherTopic = Topic::factory()->create(['project_id' => $item->project_id]);
        $this->actingAs(User::factory()->create())->withSession(['current_project_id' => $item->project_id]);
        $base = '/api/projects/'.$item->project_id;
        $this->patchJson($base.'/topics/'.$item->topic_id, ['content_column_id' => $otherTopic->content_column_id])->assertUnprocessable()->assertJsonValidationErrors('content_column_id');
        $this->patchJson($base.'/topics/'.$item->topic_id, ['content_column_id' => $item->content_column_id, 'title' => str_repeat('a', 200)])->assertOk();
        $this->assertSame($item->content_column_id, $item->topic->refresh()->content_column_id);
    }
}
