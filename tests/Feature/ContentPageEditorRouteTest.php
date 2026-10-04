<?php

namespace Tests\Feature;

use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

class ContentPageEditorRouteTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    // The DEV-W04 copy editor is an Inertia entry only — it must render the right component
    // with the four scope ids as props. All business data is fetched client-side from the
    // DEV-007A API (covered by ContentPageApiTest).
    public function test_copy_editor_route_renders_editor_with_scope_props(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
        ]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
            'topic_id' => $topic->id,
        ]);

        $this->get("/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/copy")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ContentPages/Editor')
                ->where('projectId', $project->id)
                ->where('columnId', $column->id)
                ->where('topicId', $topic->id)
                ->where('itemId', $item->id));
    }
}
