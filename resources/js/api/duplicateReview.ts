// Duplicate Review API — DEV-D11 contract, wired in DEV-W10.
//
// Real endpoints (scoped to Project → Column → Topic → ContentItem):
//   GET  /duplicate-review          -> DuplicateReviewResult
//   POST /duplicate-review/decisions -> DuplicateReviewDecision
//
// The server is the only authority for this feature. It picks the queries (the
// current working-copy fields), builds the corpus (same-project formal history),
// runs the D09/D10 core and returns each candidate's match_kind, overlap_score
// and threshold. This adapter therefore sends ids and enum values only — the
// client never transmits the compared text, a similarity score, a threshold or a
// match_kind, and it never recomputes any of them locally.
//
// Consequences worth keeping in mind when editing:
//   - There is no mock layer and no fallback payload. If the API is absent the UI
//     shows an error state; it must never render invented duplicate results.
//   - A decision is append-only server-side. After a POST we re-read the whole
//     result rather than patching `latest_decision` locally, so the panel can
//     never drift from the recorded decision history.
import type {
  ApiResponse,
  DuplicateReviewDecision,
  DuplicateReviewDecisionInput,
  DuplicateReviewResult,
} from './types';
import { http } from './http';

export type DuplicateReviewScope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

function prefix({
  projectId,
  columnId,
  topicId,
  itemId,
}: DuplicateReviewScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/duplicate-review`;
}

export const duplicateReviewApi = {
  /**
   * Duplicate-check result for the current working copy against the same project's
   * formal history. An item with no reviewable working field returns an empty
   * result (query_count = 0), which is a normal answer, not an error.
   */
  review(scope: DuplicateReviewScope): Promise<DuplicateReviewResult> {
    return http
      .get<ApiResponse<DuplicateReviewResult>>(prefix(scope))
      .then((r) => r.data.data);
  },

  /**
   * Record one review decision. The server re-verifies that the pairing still
   * forms a candidate and allocates the next decision_no in a transaction.
   */
  decide(
    scope: DuplicateReviewScope,
    input: DuplicateReviewDecisionInput,
  ): Promise<DuplicateReviewDecision> {
    return http
      .post<ApiResponse<DuplicateReviewDecision>>(`${prefix(scope)}/decisions`, input)
      .then((r) => r.data.data);
  },
};
