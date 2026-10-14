# V2 Promotion Stage 1

Foundation start: `223e2ec3400364620cefd1e966dfafeed76b3d56`.

Integration order and frozen inputs:

1. I02 Article `4867eeca33d9c7f728f02d71206abd9119cf43b6`.
2. I04 Video `969fc446ccccfd0e0a2a82e2e0aaf716e4f352fd`.
3. I05 ImageBatch `a931b58e4d2f06a276abb61362bec34f08a7fac8`.

Branch: `work/v2-p3-promotion-stage1`. Merge histories retain all three source heads. No source branch is rewritten. Native push repeatedly failed to connect to GitHub443; connector publication preserves exact tested trees and ordered merge parents, with new metadata for private staging commits only.

## Shared resolution

- Article/Video conflicts: `routes/web.php`, `ContentBranchCard.vue`, `ContentLibrary/Show.vue` were combined manually.
- ImageBatch conflict: route imports/page/API additions were appended without removing Article/Video.
- Content Library keeps image-post editing and adds its image-batch production link. A small shared link function selects official channel/format combinations, tested against all three lines and unsupported combinations. No library redesign.
- ImageBatch exposed its existing saveDraft operation as a standalone button, so users can save without queueing a run. Rendering/event regression verifies click and busy disabling.
- Browser found batch creation time was null: timestamps were disabled and the explicitly supplied timestamp was discarded by mass assignment. The model now automatically maintains only created_at (UPDATED_AT=null). Real API regression first failed on null, then passed. Nullable historical timestamps display “时间未记录”, never a fabricated epoch date.

## Contracts retained

Article: nullable article loads safely; backend-only parser/preview/apply; meaningful changes only create versions.

Video: raw workspace GET; source_page_id identity; structured source summary; saved timing from server; dirty timing is only a preview. Whitelisted PUT and source keep POST→GET retained.

ImageBatch: local selection never persists; raw workspace GET; PUT whitelist; run stays queued; copy-task assembled on server; source keep/regenerate POST→GET. Production imports contain no mock. Existing standalone DEV fixture code is not used by the production build.

## Verification

- Article/Video/ImageBatch backend targeted: initial132 tests/687 assertions; after timestamp fix ImageBatch+promotion61/318.
- Final full SQLite:471 tests/4285 assertions.
- Frontend: all three targeted suites plus staging entry/render tests, typecheck and Vite production build rerun after final changes. Final counts and final-head MySQL8.4 evidence are recorded in PR/closeout report.
- Combined migration regression verifies fresh, reset, rollback, remigrate across all seven new tables with no step count. Route regression checks uniqueness and all formal page/PUT paths.
- Browser uses isolated `p01-browser.sqlite`, port8791 and dedicated session; real Laravel pages with Vite production assets. Content Library→Article save/AI preview/apply/reload; Video save/reload/9.5s timeline/source warning/keep; ImageBatch draft save/reload/full/trial/partial/copy-task/history/source keep/regenerate all verified. Source changes were made through the official image-post preview/apply UI. Clipboard text matched server output. Console had no unexpected errors.
- No Review/I03, I06A, ReusableAssets integration, or shared AdminLayout responsive hotfix. No Foundation/main merge. Original I02/I04/I05 PRs remain open.

Stage2 awaits the separately frozen I03 and I06A inputs and explicit promotion instructions. This staging branch stops after Draft PR and final-head CI verification.
