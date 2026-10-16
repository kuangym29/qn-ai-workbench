# V2-P4-I01 — Manual review submission

Base: `9e6bdbd3c43937b142c4e764f8b5499b34a12b36`.
Branch: `work/v2-p4-review-submission`; target PR base: `codex/v2-foundation`.

## Authorized scope

Connect the four existing production editors to the existing Review Center through manual submission only. Reuse ReviewTargetType, ReviewRequestService and frozen ReviewRequest previews. No schema changes, automatic producer, generation, asset CRUD or new history UI.

## Contract

GET/POST `/api/projects/{project}/items/{item}/branches/{branch}/review-submissions/{target}`.
Targets: `image_post_copy`, `article_copy`, `video_production`, `image_batch`.
GET returns `{data:{pending,review}}`; POST returns `{data:{created,review}}` with 201/200. POST accepts no business snapshot/version payload.

Only saved server data contributes to canonical snapshots and deterministic version labels. Object keys are canonicalized, list order retained. Existing pending requests remain immutable; repeated submissions reuse the existing pending for the same branch, target and version. Decisions clear pending through existing Review rules.

Frontend request/state handling is shared. Dirty edits disable submission with a save-first hint. Submission never saves implicitly; successful POST is followed by GET and a Review Center link.

## Verification record

Backend TDD: missing controller RED, then 11 tests / 145 assertions GREEN. Full SQLite/PHP: 581 passed, 2 MySQL-only skips, 4913 assertions. Combined migration fresh/reset/rollback/re-migrate: 1 test / 79 assertions PASS, no step count dependency.

Frontend uses mounted Vue controls and real editor state tests, including four targets, dirty/busy, POST then GET, reload, retry, failed saves and refreshes. Independent review identified stale Video load errors and ImageBatch selection loss after explicit save; both received minimal fixes, with the selection regression observed RED before GREEN. No schema changes or existing Review service changes.

User explicitly accepted the ImageBatch readiness limitation: an all-empty batch cannot be distinguished from a GET-created draft without new metadata. Submission therefore requires at least one saved batch instruction, batch note or per-page special requirement. Existing fields only; no added data entry field.

Real browser verification is NOT complete. The previous in-app browser control interface is absent in this turn. Windows Computer Use was stopped because it could not confidently identify the current browser URL to enforce its safety policy. No alternative browser driver was used to bypass that stop. Article return/resubmit, Video approve, ImagePost submit, ImageBatch submit/asset timestamp checks and 353/390 geometry remain release gates.

MySQL 8.4 is verified by the branch CI after publication; record the exact run and SHA in the delivery report. This branch must remain Draft/unmerged until real browser gates are completed.
