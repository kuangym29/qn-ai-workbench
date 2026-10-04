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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AuthenticatesUser;
use Tests\TestCase;

/**
 * DEV-W10 real-API integration.
 *
 * Unlike the W09 suite, this file is not a self-check: it exists to prove that the
 * W10 Vue adapter will work against the real DEV-D11 backend. Every assertion goes
 * through the real HTTP kernel, the real frozen routes, the real controller and the
 * real database, then compares the decoded response against the field lists that
 * `resources/js/api/types.ts` declares.
 *
 * The field lists below are transcribed by hand from the TypeScript interfaces, so a
 * backend rename or a dropped key fails here rather than silently at runtime in the
 * browser.
 *
 * No mock, no stub, no fake payload: if a value reaches the UI it came from the API.
 */
class W10RealApiIntegrationTest extends TestCase
{
    use AuthenticatesUser;
    use RefreshDatabase;

    /** resources/js/api/types.ts -> DuplicateReviewResult */
    private const TS_RESULT_FIELDS = [
        'content_item_id', 'query_source', 'corpus_source',
        'query_count', 'candidate_count', 'queries',
    ];

    /** DuplicateReviewQuery */
    private const TS_QUERY_FIELDS = [
        'content_page_id', 'page_version_id', 'page_no', 'page_type', 'field', 'text', 'candidates',
    ];

    /** DuplicateReviewMatch */
    private const TS_MATCH_FIELDS = [
        'content_item_id', 'content_page_id', 'page_version_id', 'copy_revision_id',
        'revision_no', 'page_no', 'page_type', 'field', 'text',
    ];

    /** DuplicateReviewCandidate */
    private const TS_CANDIDATE_FIELDS = [
        'match', 'match_kind', 'original_exact', 'normalized_exact',
        'overlap_score', 'threshold', 'latest_decision',
    ];

    /** DuplicateReviewDecision */
    private const TS_DECISION_FIELDS = ['id', 'decision_no', 'decision', 'note', 'created_at'];

    /** The only three decisions the W10 UI can send. */
    private const ALLOWED_DECISIONS = ['confirmed_duplicate', 'ignored', 'false_positive'];

    private function context(?Project $project = null): array
    {
        $project ??= Project::factory()->create();
        $column = ContentColumn::factory()->for($project)->create();
        $topic = Topic::factory()->create(['project_id' => $project->id, 'content_column_id' => $column->id]);
        $item = ContentItem::factory()->create([
            'project_id' => $project->id, 'content_column_id' => $column->id, 'topic_id' => $topic->id,
        ]);
        $path = "/api/projects/{$project->id}/columns/{$column->id}/topics/{$topic->id}/items/{$item->id}/duplicate-review";

        return [$project, $column, $topic, $item, $path];
    }

    private function version(
        ContentItem $item,
        int $pageNo,
        PageType $type,
        array $copy,
        ?ContentCopyRevision $revision = null,
        ?ContentPage $page = null,
    ): ContentPageVersion {
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

    /**
     * Assert the payload carries at least the fields the TypeScript contract declares.
     * Extra server-side keys are allowed: the TS interfaces simply ignore them.
     */
    private function assertHasFields(array $payload, array $fields, string $label): void
    {
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $payload, "{$label} is missing the contract field {$field}");
        }
    }

    public function test_get_01_no_working_copy_returns_a_normal_empty_result(): void
    {
        [$project, , , , $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $body = $this->getJson($path)->assertOk()->json('data');

        // The panel distinguishes "nothing to check" from "checked, nothing found";
        // this is the former, and it must be a 200 with an empty projection.
        $this->assertSame(0, $body['query_count']);
        $this->assertSame(0, $body['candidate_count']);
        $this->assertSame([], $body['queries']);
        $this->assertSame('working', $body['query_source']);
        $this->assertSame('formal_history', $body['corpus_source']);
        $this->assertHasFields($body, self::TS_RESULT_FIELDS, 'GET result');
    }

    public function test_get_02_working_content_without_any_candidate(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        $this->version($item, 1, PageType::Content, ['page_title' => '一个全新的标题，没有任何历史可比']);

        $body = $this->getJson($path)->assertOk()->json('data');

        $this->assertSame(1, $body['query_count'], 'one comparable field should become one query');
        $this->assertSame(0, $body['candidate_count'], 'no formal history exists, so no candidate');
        $this->assertSame([], $body['queries'][0]['candidates']);
        $this->assertHasFields($body['queries'][0], self::TS_QUERY_FIELDS, 'GET query');
    }

    public function test_get_03_08_match_kinds_scores_and_multi_field_multi_candidate(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, , , $otherItem] = $this->context($project);
        $revisionA = $this->revision($otherItem, 1);
        $revisionB = $this->revision($otherItem, 2);

        // A long sentence on purpose: a short string's trigram overlap can sit
        // below the field threshold and silently produce zero candidates.
        $shared = '轮流不是催姐姐让，而是让两个孩子都被听见。';

        // A: original_exact (byte identical), B: normalized_exact (trailing spaces are
        // removed by the exact layer), C: overlap (one character apart, still above
        // the threshold), D: same text but a different field, so it is NOT a candidate
        // for page_title -- proving same-field-only in the live response.
        $exact = $this->version($otherItem, 1, PageType::Content, ['page_title' => $shared], $revisionA);
        $norm = $this->version($otherItem, 2, PageType::Content, ['page_title' => $shared.'  '], $revisionA);
        $overlap = $this->version($otherItem, 3, PageType::Content, ['page_title' => $shared.'了。'], $revisionB);
        $this->version($otherItem, 4, PageType::Content, ['page_small_text' => $shared], $revisionB);

        $this->version($item, 1, PageType::Content, [
            'page_title' => $shared,
            'page_small_text' => $shared,
        ]);

        $body = $this->getJson($path)->assertOk()->json('data');

        $this->assertHasFields($body, self::TS_RESULT_FIELDS, 'GET result');
        $this->assertSame('working', $body['query_source']);
        $this->assertSame('formal_history', $body['corpus_source']);

        $byField = collect($body['queries'])->keyBy('field');
        $this->assertCount(2, $byField, 'page_title and page_small_text both produce queries');

        $titleQuery = $byField['page_title'];
        $this->assertHasFields($titleQuery, self::TS_QUERY_FIELDS, 'page_title query');
        $this->assertSame('content', $titleQuery['page_type']);
        $this->assertSame($shared, $titleQuery['text']);

        $candidates = collect($titleQuery['candidates'])->keyBy(fn ($c) => $c['match']['page_version_id']);
        $this->assertCount(3, $candidates, 'three same-field candidates');

        foreach ($titleQuery['candidates'] as $candidate) {
            $this->assertHasFields($candidate, self::TS_CANDIDATE_FIELDS, 'candidate');
            $this->assertHasFields($candidate['match'], self::TS_MATCH_FIELDS, 'candidate.match');
            $this->assertNull($candidate['latest_decision'], 'no decision recorded yet');
            $this->assertContains($candidate['match_kind'], ['original_exact', 'normalized_exact', 'overlap']);
            $this->assertGreaterThanOrEqual(0.0, $candidate['overlap_score']);
            $this->assertLessThanOrEqual(1.0, $candidate['overlap_score']);
            $this->assertGreaterThan(0.0, $candidate['threshold']);
        }

        // original_exact: identical bytes, score 1.0
        $a = $candidates[$exact->id];
        $this->assertSame('original_exact', $a['match_kind']);
        $this->assertTrue($a['original_exact']);
        $this->assertEqualsWithDelta(1.0, $a['overlap_score'], 0.000001);
        // Byte-identical text is also normalized-identical, so D09 reports both
        // flags true; D10's match(true) then files it under original_exact.
        $this->assertTrue($a['normalized_exact']);

        // normalized_exact: trailing spaces removed, so still exact after normalization
        $b = $candidates[$norm->id];
        $this->assertSame('normalized_exact', $b['match_kind']);
        $this->assertFalse($b['original_exact']);
        $this->assertTrue($b['normalized_exact']);

        // overlap: above the threshold, neither exact flag set
        $c = $candidates[$overlap->id];
        $this->assertSame('overlap', $c['match_kind']);
        $this->assertFalse($c['original_exact']);
        $this->assertFalse($c['normalized_exact']);
        $this->assertGreaterThan($c['threshold'], $c['overlap_score']);

        // page_small_text query finds its own single candidate
        $smallQuery = $byField['page_small_text'];
        $this->assertCount(1, $smallQuery['candidates']);
        $this->assertSame('original_exact', $smallQuery['candidates'][0]['match_kind']);

        // revisions are surfaced, never hidden
        $this->assertSame(1, $a['match']['revision_no']);
        $this->assertSame(2, $c['match']['revision_no']);
        $this->assertNotNull($a['match']['copy_revision_id']);
    }

    public function test_decision_04_same_pairing_increments_and_latest_advances(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, , , $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Content, ['page_title' => '同一句测试文本'], $revision);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本']);

        $base = [
            'query_page_version_id' => $query->id,
            'query_field' => 'page_title',
            'match_page_version_id' => $match->id,
            'match_field' => 'page_title',
        ];

        // 1st: confirmed_duplicate
        $first = $this->postJson("$path/decisions", [...$base, 'decision' => 'confirmed_duplicate', 'note' => '首次确认'])
            ->assertCreated()->json('data');
        $this->assertSame(1, $first['decision_no']);
        $this->assertHasFields($first, self::TS_DECISION_FIELDS, 'POST decision');

        // 2nd on the same pairing: ignored -> decision_no 2
        $second = $this->postJson("$path/decisions", [...$base, 'decision' => 'ignored', 'note' => '再看一眼'])
            ->assertCreated()->json('data');
        $this->assertSame(2, $second['decision_no']);

        // 3rd: false_positive -> decision_no 3
        $third = $this->postJson("$path/decisions", [...$base, 'decision' => 'false_positive'])
            ->assertCreated()->json('data');
        $this->assertSame(3, $third['decision_no']);

        // GET reflects the newest decision only
        $body = $this->getJson($path)->assertOk()->json('data');
        $latest = $body['queries'][0]['candidates'][0]['latest_decision'];
        $this->assertHasFields($latest, self::TS_DECISION_FIELDS, 'latest_decision');
        $this->assertSame(3, $latest['decision_no']);
        $this->assertSame('false_positive', $latest['decision']);

        // append-only: the first row is untouched and still present
        $this->assertDatabaseCount('duplicate_review_decisions', 3);
        $this->assertDatabaseHas('duplicate_review_decisions', [
            'id' => $first['id'], 'decision_no' => 1, 'decision' => 'confirmed_duplicate', 'note' => '首次确认',
        ]);
    }

    public function test_decision_05_a_different_field_pairing_starts_at_one(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, , , $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Cover, [
            'cover_title' => '相同文本', 'cover_subtitle' => '相同文本',
        ], $revision);
        $query = $this->version($item, 1, PageType::Cover, [
            'cover_title' => '相同文本', 'cover_subtitle' => '相同文本',
        ]);

        $title = [
            'query_page_version_id' => $query->id, 'query_field' => 'cover_title',
            'match_page_version_id' => $match->id, 'match_field' => 'cover_title',
        ];
        $subtitle = [
            'query_page_version_id' => $query->id, 'query_field' => 'cover_subtitle',
            'match_page_version_id' => $match->id, 'match_field' => 'cover_subtitle',
        ];

        $this->postJson("$path/decisions", [...$title, 'decision' => 'confirmed_duplicate'])
            ->assertCreated()->assertJsonPath('data.decision_no', 1);
        $this->postJson("$path/decisions", [...$title, 'decision' => 'ignored'])
            ->assertCreated()->assertJsonPath('data.decision_no', 2);
        // the subtitle pairing is a different key entirely
        $this->postJson("$path/decisions", [...$subtitle, 'decision' => 'confirmed_duplicate'])
            ->assertCreated()->assertJsonPath('data.decision_no', 1);

        $byField = collect($this->getJson($path)->assertOk()->json('data.queries'))->keyBy('field');
        $this->assertSame(2, $byField['cover_title']['candidates'][0]['latest_decision']['decision_no']);
        $this->assertSame('ignored', $byField['cover_title']['candidates'][0]['latest_decision']['decision']);
        $this->assertSame(1, $byField['cover_subtitle']['candidates'][0]['latest_decision']['decision_no']);
        $this->assertSame('confirmed_duplicate', $byField['cover_subtitle']['candidates'][0]['latest_decision']['decision']);
    }

    public function test_stale_06_working_change_invalidates_the_old_pairing(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, , , $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Content, ['page_title' => '同一句测试文本'], $revision);
        $oldQuery = $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本']);

        // The reviewer records a decision on the current working version.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => $oldQuery->id, 'query_field' => 'page_title',
            'match_page_version_id' => $match->id, 'match_field' => 'page_title',
            'decision' => 'ignored', 'note' => '先忽略',
        ])->assertCreated()->assertJsonPath('data.decision_no', 1);

        // They then edit the working copy, which produces a newer page version.
        $newQuery = $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本'], null, $oldQuery->contentPage);

        // Re-submitting the old pairing is a 422; the panel must not fake success.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => $oldQuery->id, 'query_field' => 'page_title',
            'match_page_version_id' => $match->id, 'match_field' => 'page_title',
            'decision' => 'confirmed_duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('query_page_version_id');

        // The history is preserved and NOT migrated onto the new query.
        $body = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame($newQuery->id, $body['queries'][0]['page_version_id']);
        $this->assertNull($body['queries'][0]['candidates'][0]['latest_decision']);
        $this->assertDatabaseHas('duplicate_review_decisions', [
            'query_page_version_id' => $oldQuery->id, 'decision_no' => 1, 'decision' => 'ignored',
        ]);

        // A fresh decision on the new query starts at 1 again.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => $newQuery->id, 'query_field' => 'page_title',
            'match_page_version_id' => $match->id, 'match_field' => 'page_title',
            'decision' => 'confirmed_duplicate',
        ])->assertCreated()->assertJsonPath('data.decision_no', 1);
    }

    public function test_cross_project_07_is_hidden_behind_404(): void
    {
        [$project, , , $item, $path] = $this->context();
        [, , , $foreignItem] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();

        $this->version($item, 1, PageType::Content, ['page_title' => '同一句测试文本']);

        // Reading another project's item is a 404, so the panel shows an error state
        // rather than an empty candidate list.
        $foreignPath = "/api/projects/{$foreignItem->project_id}/columns/{$foreignItem->content_column_id}"
            ."/topics/{$foreignItem->topic_id}/items/{$foreignItem->id}/duplicate-review";
        $this->getJson($foreignPath)->assertNotFound();

        // Forged ancestors inside the URL are equally invisible.
        $this->getJson("/api/projects/{$project->id}/columns/999999/topics/999999/items/999999/duplicate-review")
            ->assertNotFound();

        // A match version that belongs to another project cannot be bound from here.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => 1, 'query_field' => 'page_title',
            'match_page_version_id' => 999999, 'match_field' => 'page_title',
            'decision' => 'ignored',
        ])->assertNotFound();
    }

    public function test_decision_08_invalid_field_and_non_candidate_are_422(): void
    {
        [$project, , , $item, $path] = $this->context();
        $this->postJson("/api/projects/{$project->id}/select")->assertOk();
        [, , , $otherItem] = $this->context($project);
        $revision = $this->revision($otherItem);
        $match = $this->version($otherItem, 1, PageType::Content, ['page_title' => '历史标题甲乙丙'], $revision);
        $query = $this->version($item, 1, PageType::Content, ['page_title' => '完全不同的新标题文字']);

        // A field outside the enum never reaches the service.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => $query->id, 'query_field' => 'note',
            'match_page_version_id' => $match->id, 'match_field' => 'page_title',
            'decision' => 'ignored',
        ])->assertUnprocessable()->assertJsonValidationErrors('query_field');

        // A decision outside the three allowed values is rejected too.
        $this->postJson("$path/decisions", [
            'query_page_version_id' => $query->id, 'query_field' => 'page_title',
            'match_page_version_id' => $match->id, 'match_field' => 'page_title',
            'decision' => 'maybe',
        ])->assertUnprocessable()->assertJsonValidationErrors('decision');

        // Server-owned fields cannot be forged.
        foreach (['project_id', 'content_item_id', 'decision_no'] as $forged) {
            $this->postJson("$path/decisions", [
                'query_page_version_id' => $query->id, 'query_field' => 'page_title',
                'match_page_version_id' => $match->id, 'match_field' => 'page_title',
                'decision' => 'ignored', $forged => 1,
            ])->assertUnprocessable()->assertJsonValidationErrors($forged);
        }

        $this->assertDatabaseCount('duplicate_review_decisions', 0);
        $this->assertContains('confirmed_duplicate', self::ALLOWED_DECISIONS);
    }
}
