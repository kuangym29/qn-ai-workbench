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

class TopicContentItemPagesTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    // The DEV-W03 pages are Inertia entries only — they must render the right component
    // with the right context props. All data is fetched client-side from the DEV-005 API,
    // which is covered by TopicContentItemApiTest.
    public function test_topic_page_routes_render_their_components(): void
    {
        $project = Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
        ]);

        $base = "/projects/{$project->id}/columns/{$column->id}";

        $this->get("$base/topics")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Topics/Index')
            ->where('projectId', $project->id)
            ->where('columnId', $column->id));

        $this->get("$base/topics/create")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Topics/Form')
            ->where('mode', 'create')
            ->where('projectId', $project->id)
            ->where('columnId', $column->id));

        $this->get("$base/topics/{$topic->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Topics/Form')
            ->where('mode', 'edit')
            ->where('id', $topic->id));
    }

    public function test_content_item_page_routes_render_their_components(): void
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

        $base = "/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}";

        $this->get("$base/items")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ContentItems/Index')
            ->where('projectId', $project->id)
            ->where('columnId', $column->id)
            ->where('topicId', $topic->id));

        $this->get("$base/items/create")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ContentItems/Form')
            ->where('mode', 'create')
            ->where('topicId', $topic->id));

        $this->get("$base/items/{$item->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('ContentItems/Form')
            ->where('mode', 'edit')
            ->where('id', $item->id));
    }
}
