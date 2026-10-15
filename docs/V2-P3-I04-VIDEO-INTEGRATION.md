# V2-P3-I04 Video integration

Independent branch `work/v2-p3-video-integration`, based on `223e2ec3400364620cefd1e966dfafeed76b3d56`. B04 `a4599ca9bea4459ec0d6515fd776f603fba361a6` and C03-R1 `0bb5d2396c944b7dfabc1f5d5af59ce48ef126ac` were merged with their histories. No Article implementation or Article route was added; no Foundation/main promotion.

## Formal contract

- GET `/api/projects/{p}/items/{i}/branches/{b}/video` returns the raw workspace. The client reads Axios `res.data`.
- PUT accepts only `batch_instruction`, `narration`, and units containing `source_page_id`, `dynamic_instruction`, `duration_seconds`, `note`. Unknown top-level/unit fields are rejected. No independent persistent unit ID exists.
- POST `/video/source-change` with `{action:'keep'}` is followed by GET. Failure preserves the warning and local draft. Dirty drafts are kept locally across a successful source decision, merged by still-existing source page identity, and remain visibly unsaved. No implicit PUT occurs.
- Summary is `{changed:true,message:string,source_page_count:number}|null`; the UI displays only `message`.
- GET `total_duration` and `timeline` are authoritative. Duration edits explicitly display an unsaved preview; GET restores the backend values. Timeline rows contain order/source page/label/duration/start/end; the narration note belongs to the workspace. No per-segment narration allocation is invented.
- The official page route is `/projects/{p}/content/{i}/branches/{b}/video`, entered from its ContentBranch. Existing image-post entry behavior is preserved.
- Copy task, trial, and selected segment generation buttons are disabled and labeled as not connected. No generation endpoint was added.

## Corrections and verification

Regression RED→GREEN covered raw-response unwrapping, source page identity, authoritative timing, strict fields, unit-only persistence/version append, deleted-source recovery, keep failure/draft preservation, busy edit protection, exact timeline shape, and decimal duration display/input.

Final backend SQLite suite: **380 tests / 3850 assertions**. Frontend Video suite: **33 tests passing**. Typecheck and production build pass. `git diff --check` passes. MySQL 8.4 full verification is required on the final published head through the existing `mysql84-gate`; its result is recorded in the PR checks rather than inferred from SQLite.

Browser acceptance uses a dedicated SQLite fixture and session on port 8790. No production fault route or mock is introduced: the failed keep test uses a temporary trigger only inside the disposable browser database, removed before retry. Formal entry, five editable categories, reload persistence, unit-only save, server timing, source warning, failed keep preservation, successful keep with dirty drafts, disabled busy controls, invalid-format/channel 422, cross-project 404, decimal duration, and 353px layout are checked. API status/shape assertions are also covered by backend tests; the in-app browser disallows direct JSON-document navigation.

Production DEV preview entry/module/mock files are removed. Meaningful fixture transport tests remain under `tests/frontend/v2/video`; their Node loader is retained because those TypeScript tests use it.
