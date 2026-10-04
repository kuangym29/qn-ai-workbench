<?php

namespace Tests\Feature;

use App\Enums\PageType;
use App\Models\ContentColumn;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ContentPage;
use App\Models\ContentPageVersion;
use App\Models\DuplicateReviewDecision;
use App\Models\Project;
use App\Models\Topic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

class DuplicateReviewApiTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    private function context(?Project $project = null): array
    {
        $project ??= Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $path = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/duplicate-review";

        return [$project, $item, $path];
    }

    private function version(ContentItem $item, int $pageNo, PageType $type, array $copy, ?ContentCopyRevision $revision = null, ?ContentPage $page = null): ContentPageVersion
    {
        $page ??= ContentPage::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'page_no' => $pageNo, 'page_type' => $type,
        ]);

        return ContentPageVersion::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'content_page_id' => $page->id, 'copy_revision_id' => $revision?->id,
            'version_no' => $page->versions()->count() + 1,
            'page_no_snapshot' => $revision === null ? null : $pageNo,
            'page_type_snapshot' => $revision === null ? null : $type->value,
            'cover_title' => null, 'cover_subtitle' => null, 'page_title' => null,
            'page_small_text' => null, 'closing_line' => null,
            ...$copy,
        ]);
    }

    private function revision(ContentItem $item, int $number = 1): ContentCopyRevision
    {
        return ContentCopyRevision::factory()->create([
            'project_id' => $item->project_id, 'content_item_id' => $item->id,
            'revision_no' => $number,
        ]);
    }

    private function pair(ContentItem $item, ContentItem $matchItem): array
    {
        $revision = $this->revision($matchItem);
        $match = $this->version($matchItem, 1, PageType::Content, ['page_title' => '同一句测试文本'], $revision);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本']);

        return [$query, $match];
    }

    private function decisionPayload(ContentPageVersion $query, ContentPageVersion $match): array
    {
        return [
            'query_page_version_id' => $query->id,
            'query_field' => 'page_title',
            'match_page_version_id' => $match->id,
            'match_field' => 'page_title',
            'decision' => 'confirmed_duplicate',
        ];
    }

    public function test_empty_working_query_returns_normal_empty_result(): void
    {
        [$project, , $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->getJson($path)->assertOk()->assertJsonPath('data.query_count', 0)
            ->assertJsonPath('data.candidate_count', 0)->assertJsonCount(0, 'data.queries');
    }

    public function test_working_fields_only_compare_same_field_formal_history_and_project(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $this->version($item, 2, PageType::FixedBackCover, ['page_title' => '同一句测试文本']);
        $this->version($item, 3, PageType::Cover, ['cover_title' => ' ', 'cover_subtitle' => null]);
        [$foreignProject, $foreignItem] = $this->context();
        $foreignRevision = $this->revision($foreignItem);
        $this->version($foreignItem, 1, PageType::Content, ['page_title' => '同一句测试文本'], $foreignRevision);
        $response = $this->getJson($path)->assertOk()->assertJsonPath('data.query_count', 1)
            ->assertJsonPath('data.candidate_count', 1)
            ->assertJsonPath('data.queries.0.page_version_id', $query->id)
            ->assertJsonPath('data.queries.0.candidates.0.match.page_version_id', $match->id)
            ->assertJsonPath('data.queries.0.candidates.0.match_kind', 'original_exact');
        $this->assertSame($otherItem->id, $response->json('data.queries.0.candidates.0.match.content_item_id'));
        $this->assertNotSame($foreignProject->id, $project->id);
    }

    public function test_append_only_decisions_increment_and_latest_is_returned(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $payload = $this->decisionPayload($query, $match);
        $first = $this->postJson("$path/decisions", $payload)->assertCreated()
            ->assertJsonPath('data.decision_no', 1)->json('data.id');
        $this->postJson("$path/decisions", [...$payload, 'decision' => 'ignored'])->assertCreated()
            ->assertJsonPath('data.decision_no', 2);
        $this->postJson("$path/decisions", [...$payload, 'decision' => 'false_positive'])->assertCreated()
            ->assertJsonPath('data.decision_no', 3);
        $this->getJson($path)->assertOk()->assertJsonPath('data.queries.0.candidates.0.latest_decision.decision_no', 3)
            ->assertJsonPath('data.queries.0.candidates.0.latest_decision.decision', 'false_positive');
        $this->assertDatabaseCount('duplicate_review_decisions', 3);
        $original = DuplicateReviewDecision::findOrFail($first);
        $this->assertSame('confirmed_duplicate', $original->decision);
        try {
            $original->update(['decision' => 'ignored']);
            $this->fail('Decision update must be rejected.');
        } catch (LogicException) {
            $this->assertSame('confirmed_duplicate', $original->fresh()->decision);
        }
        $this->expectException(LogicException::class);
        $original->delete();
    }

    public function test_stale_query_keeps_its_decision_but_new_working_version_has_new_identity(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$oldQuery, $match] = $this->pair($item, $otherItem);
        $oldDecisionId = $this->postJson("$path/decisions", $this->decisionPayload($oldQuery, $match))
            ->assertCreated()->assertJsonPath('data.decision_no', 1)->json('data.id');

        $newQuery = $this->version($item, 1, PageType::Content, [
            'page_title' => '同一句测试文本',
        ], page: $oldQuery->contentPage);
        $this->postJson("$path/decisions", $this->decisionPayload($oldQuery, $match))
            ->assertUnprocessable()->assertJsonValidationErrors('query_page_version_id');
        $this->getJson($path)->assertOk()
            ->assertJsonPath('data.queries.0.page_version_id', $newQuery->id)
            ->assertJsonPath('data.queries.0.candidates.0.latest_decision', null);
        $this->postJson("$path/decisions", $this->decisionPayload($newQuery, $match))
            ->assertCreated()->assertJsonPath('data.decision_no', 1);
        $this->assertDatabaseHas('duplicate_review_decisions', [
            'id' => $oldDecisionId,
            'query_page_version_id' => $oldQuery->id,
            'decision_no' => 1,
        ]);
        $this->assertDatabaseCount('duplicate_review_decisions', 2);
    }

    public function test_latest_decision_and_number_are_isolated_by_exact_four_field_pairing(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Cover, [
            'cover_title' => '相同文本', 'cover_subtitle' => '相同文本',
        ], $revision);
        $query = $this->version($item, 1, PageType::Cover, [
            'cover_title' => '相同文本', 'cover_subtitle' => '相同文本',
        ]);
        $title = [
            ...$this->decisionPayload($query, $match),
            'query_field' => 'cover_title',
            'match_field' => 'cover_title',
        ];
        $subtitle = [
            ...$this->decisionPayload($query, $match),
            'query_field' => 'cover_subtitle',
            'match_field' => 'cover_subtitle',
        ];
        $this->postJson("$path/decisions", $title)->assertCreated()->assertJsonPath('data.decision_no', 1);
        $this->postJson("$path/decisions", [...$subtitle, 'decision' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.decision_no', 1);
        $this->postJson("$path/decisions", [...$title, 'decision' => 'false_positive'])
            ->assertCreated()->assertJsonPath('data.decision_no', 2);
        $queries = collect($this->getJson($path)->assertOk()->json('data.queries'))->keyBy('field');
        $this->assertSame('false_positive', $queries['cover_title']['candidates'][0]['latest_decision']['decision']);
        $this->assertSame(2, $queries['cover_title']['candidates'][0]['latest_decision']['decision_no']);
        $this->assertSame('ignored', $queries['cover_subtitle']['candidates'][0]['latest_decision']['decision']);
        $this->assertSame(1, $queries['cover_subtitle']['candidates'][0]['latest_decision']['decision_no']);
    }

    public function test_invalid_or_stale_pair_is_rejected_without_writes(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $payload = $this->decisionPayload($query, $match);
        $this->postJson("$path/decisions", [...$payload, 'query_field' => 'note'])
            ->assertUnprocessable()->assertJsonValidationErrors('query_field');
        $this->postJson("$path/decisions", [...$payload, 'match_field' => 'cover_title'])
            ->assertUnprocessable()->assertJsonValidationErrors('match_page_version_id');
        $this->postJson("$path/decisions", [...$payload, 'project_id' => $project->id])
            ->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->version($item, 1, PageType::Content, ['page_title' => '全新草稿'], page: $query->contentPage);
        $this->postJson("$path/decisions", $payload)->assertUnprocessable()
            ->assertJsonValidationErrors('query_page_version_id');
        $this->assertDatabaseCount('duplicate_review_decisions', 0);
    }

    public function test_unknown_cross_scope_and_working_match_are_rejected(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $payload = $this->decisionPayload($query, $match);
        $this->postJson("$path/decisions", [...$payload, 'match_page_version_id' => 999999])->assertNotFound();
        $this->postJson("$path/decisions", [...$payload, 'match_page_version_id' => $query->id])
            ->assertUnprocessable()->assertJsonValidationErrors('match_page_version_id');
        [$foreignProject, $foreignItem] = $this->context();
        $foreignMatch = $this->version($foreignItem, 1, PageType::Content, ['page_title' => '同一句测试文本'], $this->revision($foreignItem));
        $this->postJson("$path/decisions", [...$payload, 'match_page_version_id' => $foreignMatch->id])->assertNotFound();
        $foreignQuery = $this->version($foreignItem, 2, PageType::Content, ['page_title' => '同一句测试文本']);
        $this->postJson("$path/decisions", [...$payload, 'query_page_version_id' => $foreignQuery->id])->assertNotFound();
        $this->getJson(str_replace("/projects/{$project->id}", "/projects/{$foreignProject->id}", $path))->assertNotFound();
        $this->assertDatabaseCount('duplicate_review_decisions', 0);
    }

    public function test_query_from_another_item_and_forged_route_ancestors_are_hidden(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem, $otherPath] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $otherQuery = $this->version($otherItem, 2, PageType::Content, ['page_title' => '同一句测试文本']);
        $this->postJson("$path/decisions", [
            ...$this->decisionPayload($query, $match),
            'query_page_version_id' => $otherQuery->id,
        ])->assertNotFound();
        $forgedPath = substr($otherPath, 0, strrpos($otherPath, '/items/'))."/items/{$item->id}/duplicate-review";
        $this->getJson($forgedPath)->assertNotFound();
        $this->postJson("$forgedPath/decisions", $this->decisionPayload($query, $match))->assertNotFound();
        $this->assertDatabaseCount('duplicate_review_decisions', 0);
    }

    public function test_historical_revision_of_current_item_is_a_candidate_and_limit_is_respected(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $revision = $this->revision($item);
        $page = ContentPage::factory()->create([
            'project_id' => $project->id, 'content_item_id' => $item->id, 'page_no' => 1, 'page_type' => PageType::Content,
        ]);
        $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本'], $revision, $page);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本'], page: $page);
        $this->getJson("$path?limit=1")->assertOk()->assertJsonPath('data.query_count', 1)
            ->assertJsonPath('data.queries.0.page_version_id', $query->id)
            ->assertJsonPath('data.candidate_count', 1);
        $this->getJson("$path?limit=0")->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_multiple_pages_and_fields_use_exact_normalized_and_overlap_rules(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $base = '轮流不是催姐姐让，而是让两个孩子都被听见。';
        $this->version($otherItem, 1, PageType::Cover, [
            'cover_title' => '封面标题', 'cover_subtitle' => '仅副标题',
        ], $revision);
        $this->version($otherItem, 2, PageType::ColumnClosing, ['closing_line' => $base], $revision);
        $this->version($otherItem, 3, PageType::ColumnClosing, ['closing_line' => $base.'  '], $revision);
        $this->version($otherItem, 4, PageType::ColumnClosing, [
            'closing_line' => '轮流不是催姐姐让，而是让两个孩子都被听见了。',
        ], $revision);
        $this->version($item, 1, PageType::Cover, [
            'cover_title' => '封面标题', 'cover_subtitle' => '不同副标题',
        ]);
        $this->version($item, 2, PageType::ColumnClosing, ['closing_line' => $base]);
        $response = $this->getJson("$path?limit=2")->assertOk()
            ->assertJsonPath('data.query_count', 3)
            ->assertJsonPath('data.candidate_count', 3);
        $queries = collect($response->json('data.queries'))->keyBy('field');
        $this->assertCount(1, $queries['cover_title']['candidates']);
        $this->assertCount(0, $queries['cover_subtitle']['candidates']);
        $this->assertSame(
            ['original_exact', 'normalized_exact'],
            array_column($queries['closing_line']['candidates'], 'match_kind')
        );
        $all = $this->getJson("$path?limit=3")->assertOk();
        $closing = collect($all->json('data.queries'))->firstWhere('field', 'closing_line');
        $this->assertSame('overlap', $closing['candidates'][2]['match_kind']);
    }

    public function test_non_candidate_and_formal_latest_do_not_produce_query_or_decision(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Content, ['page_title' => '毫不相干的另一段文字'], $revision);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => '完全不同的测试文案']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.query_count', 1)
            ->assertJsonPath('data.candidate_count', 0);
        $this->postJson("$path/decisions", $this->decisionPayload($query, $match))
            ->assertUnprocessable()->assertJsonValidationErrors('match_page_version_id');

        $formal = $this->revision($item);
        $this->version($item, 1, PageType::Content, ['page_title' => '正式确认的新稿'], $formal, $query->contentPage);
        $this->getJson($path)->assertOk()->assertJsonPath('data.query_count', 0);
        $this->assertDatabaseCount('duplicate_review_decisions', 0);
    }

    public function test_decision_number_is_database_unique_and_query_fk_is_scoped(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $this->postJson("$path/decisions", $this->decisionPayload($query, $match))->assertCreated();
        try {
            DB::table('duplicate_review_decisions')->insert([
                'project_id' => $project->id, 'content_item_id' => $item->id,
                'query_page_version_id' => $query->id, 'query_field' => 'page_title',
                'match_page_version_id' => $match->id, 'match_field' => 'page_title',
                'decision_no' => 1, 'decision' => 'ignored',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('The database must reject a simulated duplicate-number race.');
        } catch (QueryException) {
            $this->assertDatabaseCount('duplicate_review_decisions', 1);
            $this->assertDatabaseHas('duplicate_review_decisions', [
                'query_page_version_id' => $query->id,
                'match_page_version_id' => $match->id,
                'decision_no' => 1,
                'decision' => 'confirmed_duplicate',
            ]);
        }
        $this->postJson("$path/decisions", [...$this->decisionPayload($query, $match), 'decision' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.decision_no', 2);
    }

    public function test_insert_time_unique_race_returns_controlled_422_and_preserves_history(): void
    {
        [$project, $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, $otherItem] = $this->context($project);
        [$query, $match] = $this->pair($item, $otherItem);
        $payload = $this->decisionPayload($query, $match);
        $firstId = $this->postJson("$path/decisions", $payload)->assertCreated()
            ->assertJsonPath('data.decision_no', 1)->json('data.id');

        $eventName = 'eloquent.creating: '.DuplicateReviewDecision::class;
        Event::listen($eventName, static function (DuplicateReviewDecision $pending): void {
            DB::table('duplicate_review_decisions')->insert([
                'project_id' => $pending->project_id,
                'content_item_id' => $pending->content_item_id,
                'query_page_version_id' => $pending->query_page_version_id,
                'query_field' => $pending->query_field,
                'match_page_version_id' => $pending->match_page_version_id,
                'match_field' => $pending->match_field,
                'decision_no' => $pending->decision_no,
                'decision' => 'ignored',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        try {
            $this->postJson("$path/decisions", [...$payload, 'decision' => 'false_positive'])
                ->assertUnprocessable()->assertJsonValidationErrors('decision');
        } finally {
            Event::forget($eventName);
        }
        $this->assertDatabaseCount('duplicate_review_decisions', 1);
        $this->assertDatabaseHas('duplicate_review_decisions', [
            'id' => $firstId,
            'decision_no' => 1,
            'decision' => 'confirmed_duplicate',
        ]);
    }
}
