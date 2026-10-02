<?php

namespace Tests\Feature;

use App\Enums\ArtworkStatus;
use App\Enums\CopyStatus;
use App\Models\ChannelTask;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionTaskApiTest extends TestCase
{
    use RefreshDatabase;

    private function context(): array
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        return [$project, $column, $topic, $item,
            "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/production"];
    }

    private function revision(ContentItem $item, int $number = 1): ContentCopyRevision
    {
        return ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id, 'revision_no' => $number,
        ]);
    }

    public function test_nullable_legacy_task_and_scoped_revision_foreign_key(): void
    {
        $a = ContentItem::factory()->create();
        $b = ContentItem::factory()->create(['project_id' => $a->project_id]);
        $foreign = ContentItem::factory()->create();
        $aRevision = $this->revision($a);
        $bRevision = $this->revision($b);
        $foreignRevision = $this->revision($foreign);
        $legacy = ProductionTask::factory()->create(['project_id' => $a->project_id, 'content_item_id' => $a->id]);
        $this->assertNull($legacy->copy_revision_id);
        $legacy->forceFill(['copy_revision_id' => $aRevision->id])->save();
        $this->assertTrue($legacy->fresh()->copyRevision->is($aRevision));
        $this->assertTrue($aRevision->productionTasks()->first()->is($legacy));
        foreach ([$bRevision, $foreignRevision] as $wrong) {
            try {
                DB::table('production_tasks')->where('id', $legacy->id)->update(['copy_revision_id' => $wrong->id]);
                $this->fail('Expected scoped revision FK rejection.');
            } catch (QueryException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertSame($aRevision->id, $legacy->fresh()->copy_revision_id);
    }

    public function test_revision_reference_restricts_deletion_while_legacy_task_cascades_with_item(): void
    {
        $item = ContentItem::factory()->create();
        $revision = $this->revision($item);
        ProductionTask::factory()->forCopyRevision($revision)->create();
        try {
            DB::table('content_items')->where('id', $item->id)->delete();
            $this->fail('Expected formal revision history to restrict ContentItem deletion.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        try {
            DB::table('projects')->where('id', $item->project_id)->delete();
            $this->fail('Expected formal revision history to restrict Project deletion.');
        } catch (QueryException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertDatabaseHas('production_tasks', ['content_item_id' => $item->id, 'copy_revision_id' => $revision->id]);

        $legacy = ContentItem::factory()->create();
        ProductionTask::factory()->create(['project_id' => $legacy->project_id, 'content_item_id' => $legacy->id]);
        DB::table('content_items')->where('id', $legacy->id)->delete();
        $this->assertDatabaseMissing('production_tasks', ['content_item_id' => $legacy->id]);

        $projectOnly = ContentItem::factory()->create();
        ProductionTask::factory()->create(['project_id' => $projectOnly->project_id, 'content_item_id' => $projectOnly->id]);
        DB::table('projects')->where('id', $projectOnly->project_id)->delete();
        $this->assertDatabaseMissing('production_tasks', ['content_item_id' => $projectOnly->id]);
    }

    public function test_create_requires_confirmed_revision_pins_latest_and_rejects_duplicate(): void
    {
        [$project, , , $item, $url] = $this->context();
        $this->getJson($url)->assertOk()->assertJsonPath('data', null);
        foreach ([CopyStatus::NotStarted, CopyStatus::Editing, CopyStatus::PendingConfirmation] as $status) {
            $item->update(['copy_status' => $status]);
            $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('production');
        }
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->revision($item);
        $latest = $this->revision($item, 2);
        $created = $this->postJson($url)->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.copy_revision_id', $latest->id)
            ->assertJsonPath('data.copy_revision_no', 2)
            ->assertJsonPath('data.artwork_status', 'not_started')
            ->assertJsonPath('data.is_copy_revision_current', true);
        $this->getJson($url)->assertOk()->assertJsonPath('data.id', $created->json('data.id'));
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->assertDatabaseCount('production_tasks', 1);
        $this->assertDatabaseCount('channel_tasks', 0);
    }

    public function test_scope_and_validation_reject_wrong_ancestors_and_forged_fields(): void
    {
        [, , , $item, $url] = $this->context();
        $other = Project::factory()->create();
        $otherColumn = ContentColumn::factory()->for($other)->create();
        $otherTopic = Topic::factory()->create(['project_id' => $other->id, 'content_column_id' => $otherColumn->id]);
        $this->getJson(str_replace('/production', '', $url).'/production')->assertOk();
        $wrong = "/api/projects/{$other->id}/columns/{$otherColumn->id}/topics/{$otherTopic->id}/items/{$item->id}/production";
        $this->getJson($wrong)->assertNotFound();
        $this->postJson($wrong)->assertNotFound();
        $this->patchJson($wrong, ['artwork_status' => 'approved'])->assertNotFound();
        $this->postJson($wrong.'/use-current-copy')->assertNotFound();
        foreach (['project_id', 'content_item_id', 'copy_revision_id'] as $field) {
            $this->postJson($url, [$field => 999])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson($url.'/use-current-copy', ['copy_revision_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('copy_revision_id');
        $this->patchJson($url, ['artwork_status' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('artwork_status');
        $this->patchJson($url, ['artwork_status' => 'in_progress', 'project_id' => 999])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
    }

    public function test_artwork_approval_uses_current_formal_revision_and_status_dimensions_stay_independent(): void
    {
        [, , , $item, $url] = $this->context();
        $first = $this->revision($item);
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->postJson($url)->assertCreated();
        foreach (['in_progress', 'pending_review', 'not_started'] as $status) {
            $this->patchJson($url, ['artwork_status' => $status])->assertOk()->assertJsonPath('data.artwork_status', $status);
        }
        $this->patchJson($url, ['artwork_status' => 'approved'])->assertOk();
        $this->assertSame(CopyStatus::Confirmed, $item->fresh()->copy_status);
        $item->update(['copy_status' => CopyStatus::Editing]);
        $this->patchJson($url, ['artwork_status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('artwork_status');
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $this->revision($item, 2);
        $this->getJson($url)->assertOk()->assertJsonPath('data.copy_revision_id', $first->id)
            ->assertJsonPath('data.is_copy_revision_current', false);
        $this->patchJson($url, ['artwork_status' => 'pending_review'])->assertOk();
        $this->patchJson($url, ['artwork_status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('artwork_status');
        $this->patchJson($url, ['artwork_status' => 'approved', 'copy_revision_id' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('copy_revision_id');
        $this->assertDatabaseCount('channel_tasks', 0);
    }

    public function test_use_current_copy_resets_artwork_and_channel_tasks_block_switch(): void
    {
        [, , , $item, $url] = $this->context();
        $this->revision($item);
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        $taskId = $this->postJson($url)->assertCreated()->json('data.id');
        $this->patchJson($url, ['artwork_status' => ArtworkStatus::PendingReview->value])->assertOk();
        $second = $this->revision($item, 2);
        $this->postJson($url.'/use-current-copy')->assertOk()
            ->assertJsonPath('data.copy_revision_id', $second->id)
            ->assertJsonPath('data.artwork_status', 'not_started')
            ->assertJsonPath('data.is_copy_revision_current', true);
        $this->postJson($url.'/use-current-copy')->assertOk()->assertJsonPath('data.id', $taskId);
        $this->assertDatabaseCount('production_tasks', 1);
        $this->revision($item, 3);
        $this->patchJson($url, ['artwork_status' => 'pending_review'])->assertOk();
        $item->update(['copy_status' => CopyStatus::Editing]);
        $this->postJson($url.'/use-current-copy')->assertUnprocessable()->assertJsonValidationErrors('production');
        $item->update(['copy_status' => CopyStatus::Confirmed]);
        ChannelTask::factory()->create([
            'project_id' => $item->project_id, 'production_task_id' => $taskId,
        ]);
        $this->postJson($url.'/use-current-copy')->assertUnprocessable()->assertJsonValidationErrors('production');
        $this->assertDatabaseHas('production_tasks', [
            'id' => $taskId, 'copy_revision_id' => $second->id, 'artwork_status' => 'pending_review',
        ]);
        $this->assertDatabaseCount('channel_tasks', 1);
    }

    public function test_copy_confirmation_does_not_create_or_rebind_production_automatically(): void
    {
        [, , , $item, $url] = $this->context();
        $base = substr($url, 0, -strlen('/production'));
        $page = $this->postJson($base.'/pages', ['page_no' => 1, 'page_type' => 'content'])
            ->assertCreated()->json('data.id');
        $this->postJson($base."/pages/{$page}/drafts", ['page_title' => '初稿'])->assertCreated();
        $first = $this->postJson($base.'/copy/confirm')->assertCreated()->json('data.id');
        $this->assertDatabaseCount('production_tasks', 0);
        $this->postJson($url)->assertCreated()->assertJsonPath('data.copy_revision_id', $first);
        $this->patchJson($url, ['artwork_status' => 'pending_review'])->assertOk();

        $this->postJson($base."/pages/{$page}/drafts", ['page_title' => '第二版'])->assertCreated();
        $this->assertSame(CopyStatus::Editing, $item->fresh()->copy_status);
        $second = $this->postJson($base.'/copy/confirm')->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.copy_revision_id', $first)
            ->assertJsonPath('data.artwork_status', 'pending_review')
            ->assertJsonPath('data.is_copy_revision_current', false);
        $this->assertDatabaseCount('production_tasks', 1);
        $this->assertDatabaseCount('channel_tasks', 0);
    }
}
