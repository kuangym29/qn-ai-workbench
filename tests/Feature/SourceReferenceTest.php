<?php

namespace Tests\Feature;

use App\Enums\SourceAuthority;
use App\Enums\SourceRole;
use App\Models\ContentColumn;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\SourceReference;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SourceReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function item(Project $project): ContentItem
    {
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
        ]);

        return ContentItem::factory()->create([
            'project_id' => $project->id,
            'content_column_id' => $column->id,
            'topic_id' => $topic->id,
        ]);
    }

    public function test_enums_define_exact_roles_authorities_defaults_and_scopes(): void
    {
        $this->assertSame([
            'final_image_copy', 'source_script', 'content_ledger',
            'closing_line_registry', 'navigation_index',
        ], array_column(SourceRole::cases(), 'value'));
        $this->assertSame([
            'authoritative', 'evidence', 'index', 'reference',
        ], array_column(SourceAuthority::cases(), 'value'));

        foreach ([
            SourceRole::FinalImageCopy->value => [SourceAuthority::Authoritative, true],
            SourceRole::SourceScript->value => [SourceAuthority::Evidence, true],
            SourceRole::ContentLedger->value => [SourceAuthority::Index, false],
            SourceRole::ClosingLineRegistry->value => [SourceAuthority::Reference, false],
            SourceRole::NavigationIndex->value => [SourceAuthority::Index, false],
        ] as $role => [$authority, $itemScoped]) {
            $this->assertSame($authority, SourceRole::from($role)->defaultAuthority());
            $this->assertSame($itemScoped, SourceRole::from($role)->isContentItemScoped());
        }
    }

    public function test_project_and_item_references_have_relations_and_enum_casts(): void
    {
        $project = Project::factory()->create();
        $item = $this->item($project);
        $ledger = SourceReference::factory()->for($project)->contentLedger()->create();
        $copy = SourceReference::factory()->for($project)->for($item, 'contentItem')
            ->finalImageCopy()->create();

        $this->assertNull($ledger->content_item_id);
        $this->assertSame($project->id, $ledger->project_id);
        $this->assertSame($item->id, $copy->content_item_id);
        $this->assertSame($project->id, $copy->project_id);
        $this->assertSame($project->id, $ledger->project->id);
        $this->assertSame($item->id, $copy->contentItem->id);
        $this->assertSame(2, $project->sourceReferences()->count());
        $this->assertSame(1, $item->sourceReferences()->count());

        $fresh = $copy->fresh();
        $this->assertSame(SourceRole::FinalImageCopy, $fresh->role);
        $this->assertSame(SourceAuthority::Authoritative, $fresh->authority);
        $this->assertSame('final_image_copy', $fresh->getRawOriginal('role'));
        $this->assertSame('authoritative', $fresh->getRawOriginal('authority'));
    }

    public function test_database_composite_foreign_key_rejects_cross_project_item(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $itemB = $this->item($projectB);

        $this->expectException(QueryException::class);
        DB::table('source_references')->insert([
            'project_id' => $projectA->id,
            'content_item_id' => $itemB->id,
            'role' => 'source_script',
            'authority' => 'evidence',
            'source_path' => 'scripts/other-project.md',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_project_delete_removes_project_and_item_references(): void
    {
        $project = Project::factory()->create();
        $item = $this->item($project);
        SourceReference::factory()->for($project)->contentLedger()->create();
        SourceReference::factory()->for($project)->for($item, 'contentItem')
            ->finalImageCopy()->create();

        $project->delete();

        $this->assertDatabaseCount('source_references', 0);
        $this->assertDatabaseMissing('content_items', ['id' => $item->id]);
    }

    public function test_item_delete_removes_only_its_references_and_keeps_project_reference(): void
    {
        $project = Project::factory()->create();
        $item = $this->item($project);
        $ledger = SourceReference::factory()->for($project)->contentLedger()->create();
        SourceReference::factory()->for($project)->for($item, 'contentItem')
            ->sourceScript()->create();

        $item->delete();

        $this->assertDatabaseHas('source_references', ['id' => $ledger->id, 'content_item_id' => null]);
        $this->assertDatabaseCount('source_references', 1);
    }

    public function test_same_source_path_can_be_shared_by_multiple_items(): void
    {
        $project = Project::factory()->create();
        $first = $this->item($project);
        $second = $this->item($project);
        $path = '01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md';
        foreach ([$first, $second] as $item) {
            SourceReference::factory()->for($project)->for($item, 'contentItem')
                ->finalImageCopy()->create(['source_path' => $path]);
        }

        $this->assertSame(2, SourceReference::query()->where('source_path', $path)->count());
    }

    public function test_one_item_can_reference_multiple_source_script_versions(): void
    {
        $project = Project::factory()->create();
        $item = $this->item($project);
        foreach (['scripts/v2.md', 'scripts/v6.md'] as $path) {
            SourceReference::factory()->for($project)->for($item, 'contentItem')
                ->sourceScript()->create(['source_path' => $path]);
        }

        $this->assertSame(2, $item->sourceReferences()->where('role', SourceRole::SourceScript)->count());
    }

    public function test_all_factory_states_use_the_expected_authority_and_scope(): void
    {
        $default = SourceReference::factory()->create();
        $this->assertSame(SourceRole::ContentLedger, $default->role);
        $this->assertSame(SourceAuthority::Index, $default->authority);
        $this->assertNull($default->content_item_id);
        $this->assertSame($default->project_id, $default->project->id);

        $project = Project::factory()->create();
        $item = $this->item($project);
        foreach (['finalImageCopy', 'sourceScript'] as $state) {
            $reference = SourceReference::factory()->for($project)->for($item, 'contentItem')
                ->$state()->create();
            $this->assertSame($reference->role->defaultAuthority(), $reference->authority);
            $this->assertTrue($reference->role->isContentItemScoped());
        }
        foreach (['contentLedger', 'closingLineRegistry', 'navigationIndex'] as $state) {
            $reference = SourceReference::factory()->for($project)->$state()->create();
            $this->assertSame($reference->role->defaultAuthority(), $reference->authority);
            $this->assertFalse($reference->role->isContentItemScoped());
            $this->assertNull($reference->content_item_id);
        }
    }
}
