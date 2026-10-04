<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\ContentPageVersion;
use App\Models\DuplicateReviewDecision;
use App\Services\DuplicateCheck\DuplicateCandidate;
use App\Services\DuplicateCheck\DuplicateCandidateFinder;
use App\Services\DuplicateCheck\DuplicateComparableEntry;
use App\Services\DuplicateCheck\DuplicateCorpusBuilder;
use App\Services\DuplicateCheck\DuplicateField;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DuplicateReviewService
{
    public function __construct(
        private readonly DuplicateCorpusBuilder $builder,
        private readonly DuplicateCandidateFinder $finder,
    ) {}

    public function review(ContentItem $item, int $limit = 20): array
    {
        $working = $this->workingVersions($item);
        $queries = $this->builder->build(array_map(fn (ContentPageVersion $version): array => $this->snapshot($version, true), $working));
        $formal = ContentPageVersion::query()
            ->where('project_id', $item->project_id)
            ->whereNotNull('copy_revision_id')
            ->with('contentCopyRevision')
            ->get();
        $corpus = $this->builder->build($formal->map(fn (ContentPageVersion $version): array => $this->snapshot($version, false))->all());
        $formalByIdentity = $formal->keyBy(fn (ContentPageVersion $version): string => $this->identity(
            (string) $version->content_item_id,
            (string) $version->content_page_id,
            (string) $version->copy_revision_id
        ));
        $workingByPage = collect($working)->keyBy('content_page_id');
        $decisions = DuplicateReviewDecision::query()->where('project_id', $item->project_id)
            ->where('content_item_id', $item->id)->orderByDesc('decision_no')->orderByDesc('id')
            ->get()->unique(fn (DuplicateReviewDecision $decision): string => $this->pairKey(
                $decision->query_page_version_id, $decision->query_field,
                $decision->match_page_version_id, $decision->match_field
            ))->keyBy(fn (DuplicateReviewDecision $decision): string => $this->pairKey(
                $decision->query_page_version_id, $decision->query_field,
                $decision->match_page_version_id, $decision->match_field
            ));

        $results = [];
        $candidateCount = 0;
        foreach ($queries as $query) {
            $queryVersion = $workingByPage->get($query->contentPageId);
            $candidates = [];
            foreach ($this->finder->find($query, $corpus, $limit) as $candidate) {
                $matchVersion = $formalByIdentity->get($this->identity(
                    $candidate->match->contentItemId,
                    $candidate->match->contentPageId,
                    $candidate->match->copyRevisionId
                ));
                $latest = $decisions->get($this->pairKey(
                    $queryVersion->id, $query->field->value,
                    $matchVersion->id, $candidate->match->field->value
                ));
                $candidates[] = $this->candidateData($candidate, $matchVersion, $latest);
            }
            $candidateCount += count($candidates);
            $results[] = [
                'content_page_id' => $queryVersion->content_page_id,
                'page_version_id' => $queryVersion->id,
                'page_no' => $query->pageNo,
                'page_type' => $query->pageType,
                'field' => $query->field->value,
                'text' => $query->text,
                'candidates' => $candidates,
            ];
        }

        return [
            'content_item_id' => $item->id,
            'query_source' => 'working',
            'corpus_source' => 'formal_history',
            'query_count' => count($results),
            'candidate_count' => $candidateCount,
            'queries' => $results,
        ];
    }

    public function appendDecision(ContentItem $item, array $data): DuplicateReviewDecision
    {
        return DB::transaction(function () use ($item, $data): DuplicateReviewDecision {
            // Serialize decision number allocation for every pair under this item.
            ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            $queryVersion = ContentPageVersion::query()->where('project_id', $item->project_id)
                ->where('content_item_id', $item->id)->with('contentPage')
                ->findOrFail($data['query_page_version_id']);
            $matchVersion = ContentPageVersion::query()->where('project_id', $item->project_id)
                ->with(['contentPage', 'contentCopyRevision'])
                ->findOrFail($data['match_page_version_id']);

            $latestVersionId = ContentPageVersion::query()
                ->where('content_page_id', $queryVersion->content_page_id)
                ->orderByDesc('version_no')->value('id');
            if ($queryVersion->copy_revision_id !== null || $queryVersion->id !== $latestVersionId) {
                throw ValidationException::withMessages(['query_page_version_id' => 'The working version is no longer current.']);
            }
            if ($matchVersion->copy_revision_id === null || $matchVersion->contentCopyRevision === null) {
                throw ValidationException::withMessages(['match_page_version_id' => 'The match must be a formal revision.']);
            }

            $queryField = DuplicateField::from($data['query_field']);
            $matchField = DuplicateField::from($data['match_field']);
            $query = $this->entryFor($queryVersion, $queryField, true);
            $match = $this->entryFor($matchVersion, $matchField, false);
            if ($query === null || $match === null || $this->finder->find($query, [$match], 1) === []) {
                throw ValidationException::withMessages(['match_page_version_id' => 'This pair is not a duplicate candidate.']);
            }

            $next = (int) DuplicateReviewDecision::query()
                ->where('project_id', $item->project_id)
                ->where('content_item_id', $item->id)
                ->where('query_page_version_id', $queryVersion->id)
                ->where('query_field', $queryField->value)
                ->where('match_page_version_id', $matchVersion->id)
                ->where('match_field', $matchField->value)
                ->max('decision_no') + 1;

            return DuplicateReviewDecision::create([
                'project_id' => $item->project_id,
                'content_item_id' => $item->id,
                'query_page_version_id' => $queryVersion->id,
                'query_field' => $queryField->value,
                'match_page_version_id' => $matchVersion->id,
                'match_field' => $matchField->value,
                'decision_no' => $next,
                'decision' => $data['decision'],
                'note' => $data['note'] ?? null,
            ]);
        });
    }

    private function workingVersions(ContentItem $item): array
    {
        $pages = $item->contentPages()->get()->keyBy('id');
        $latest = ContentPageVersion::query()
            ->where('project_id', $item->project_id)
            ->where('content_item_id', $item->id)
            ->orderByDesc('version_no')->get()->unique('content_page_id');

        return $latest->filter(fn (ContentPageVersion $version): bool => $version->copy_revision_id === null)
            ->each(fn (ContentPageVersion $version) => $version->setRelation('contentPage', $pages->get($version->content_page_id)))
            ->all();
    }

    private function snapshot(ContentPageVersion $version, bool $working): array
    {
        return [
            'content_item_id' => (string) $version->content_item_id,
            'content_page_id' => (string) $version->content_page_id,
            'copy_revision_id' => $working ? '' : (string) $version->copy_revision_id,
            'page_no_snapshot' => $working ? $version->contentPage->page_no : $version->page_no_snapshot,
            'page_type_snapshot' => $working ? $version->contentPage->page_type->value : $version->page_type_snapshot,
            'cover_title' => $version->cover_title,
            'cover_subtitle' => $version->cover_subtitle,
            'page_title' => $version->page_title,
            'page_small_text' => $version->page_small_text,
            'closing_line' => $version->closing_line,
        ];
    }

    private function entryFor(ContentPageVersion $version, DuplicateField $field, bool $working): ?DuplicateComparableEntry
    {
        foreach ($this->builder->build([$this->snapshot($version, $working)]) as $entry) {
            if ($entry->field === $field) {
                return $entry;
            }
        }

        return null;
    }

    private function candidateData(
        DuplicateCandidate $candidate,
        ContentPageVersion $match,
        ?DuplicateReviewDecision $latest,
    ): array {
        return [
            'match' => [
                'content_item_id' => $match->content_item_id,
                'content_page_id' => $match->content_page_id,
                'page_version_id' => $match->id,
                'copy_revision_id' => $match->copy_revision_id,
                'revision_no' => $match->contentCopyRevision->revision_no,
                'page_no' => $candidate->match->pageNo,
                'page_type' => $candidate->match->pageType,
                'field' => $candidate->match->field->value,
                'text' => $candidate->match->text,
            ],
            'match_kind' => $candidate->matchKind,
            'original_exact' => $candidate->originalExact,
            'normalized_exact' => $candidate->normalizedExact,
            'overlap_score' => $candidate->overlapScore,
            'threshold' => $candidate->threshold,
            'latest_decision' => $latest === null ? null : [
                'id' => $latest->id,
                'decision_no' => $latest->decision_no,
                'decision' => $latest->decision,
                'note' => $latest->note,
                'created_at' => $latest->created_at?->toISOString(),
            ],
        ];
    }

    private function identity(string $item, string $page, string $revision): string
    {
        return "$item:$page:$revision";
    }

    private function pairKey(int $queryVersion, string $queryField, int $matchVersion, string $matchField): string
    {
        return "$queryVersion:$queryField:$matchVersion:$matchField";
    }
}
