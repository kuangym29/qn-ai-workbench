# Stage2B final promotion acceptance

Start: I06B 2358b4f459d52d7d56b4cf1f6753a6eee47ca57a.
Absorbed: I03 f059d4ab3d6a10ac28dd540581e5bf6d5d05a7f8.
P02A ancestor: aea4348382587ae339ecacad9f86bafb3e1b2d53.
Verified business merge: 8eff37bfbee7cac5c3dd4b1db77f3f90b8a9edce; two original source parents retained.
Branch: work/v2-p3-promotion-stage2b. Draft PR44 targets codex/v2-foundation.

Shared conflicts were limited to routes/web.php and Project.php. The final route families retain ImagePost, Article, Video, ImageBatch, ReusableAssets and Review. Both reusableAssets and reviewRequests relations survive alongside Foundation relations. AppServiceProvider and ImageBatch DI are unchanged from I06B.

Integration defect repaired: existing navigation still selected the retired /review placeholder. Rendered click and route regressions first failed, then passed after navigation switched to /reviews and the placeholder route was retired. No alternate formal Review entry remains. This does not add a Review producer.

## Automated evidence

- I06B baseline SQLite:520 passed.
- Combined SQLite:570 passed,2 MySQL-only skips,4766 assertions.
- Real MySQL8.4.11 full:585 tests,4947 assertions; gate-focused:13 tests,122 assertions. Includes Review concurrency and actual asset binding chain.
- All frontend test files:177 passed,0 failed; Foundation shell/rendered navigation included. Browser-only harness files were verified through the supported browser interface rather than launched with a second browser driver.
- Typecheck and Vite production build passed. Build reports a nonblocking approximately546KB chunk-size notice; no unrelated bundling refactor.
- Combined migrate:fresh/reset/rollback/re-migrate passed without step=N. New migration regression includes every module table.
- Route smoke, duplicate declaration detection, all Project relations and production asset DI regression passed.
- CI: https://github.com/kuangym29/qn-ai-workbench/actions/runs/38560984799 (PHP and MySQL SUCCESS).

## Real browser evidence

Isolated Laravel8794 with real Vite build and disposable SQLite; no production mock fallback.

- ImagePost: official ContentBranch entry, GET, manual save/reload, backend preview/apply/reload, readonly fixed back cover; page edit creates a real source version.
- Article: article=null empty form, save/reload, backend preview/apply/reload and structured image suggestions retained.
- Video: instructions/narration/dynamic/duration/note saved; first unit6s and total18s/timeline returned by server; actual source version warning and Keep→GET→reload clears warning.
- ImageBatch: draft save; full/trial/partial create exactly3 queued history records; copied server task; Keep and Regenerate clear source warnings, Regenerate shows needs_revision/需修改. No image generation.
- ReusableAssets: characters/scenes,existing/missing filters,null placeholders and empty project states;353/390 layouts. Production default characters=[],scene=null,assets=[] remains. Asset last_used_at stays at original sentinel/null values after GET/copy/queued runs.
- Review: pending/empty,text/lines/items/null,approve/remove-from-pending,required comment rejection,changes_requested,comment cancel,actual other-actor409,missing-detail404 and cross-project404.353/390 list/detail/controls/comment all operate.
- Drawer:353 overlay/ESC/reclick close;390 navigation goes to formal Review route and closes drawer.
-23 document measurements:all scrollWidth<=clientWidth. Content Library tested353/390/768/1024/1440; all production business pages and Content Detail tested353;390 extra business checks; desktop Sidebar remains visible240px at768/1024/1440.
- Normal console warnings/errors:0. Deliberate409/404 responses are expected acceptance cases.

I03's deferred353px gate is closed by these Stage2B checks. No global html/body overflow-x:hidden was added.

## Final boundaries

Review retains {data:...}; ReusableAsset retains top-level{characters,scenes}; ImageBatch retains its workspace and save whitelist. No inferred assets, asset usage touch, automatic Review submissions, generation, CRUD, upload, notifications or new permission system.

Fresh independent review completed across all20 focus items. One candidate was reproduced and fixed: Review long URL/text/lines/items and long version labels could be clipped by cards despite a passing document width. Before fix at353px: text content1580px/client287px; items right1734px/row320px; metadata right442px/button321px. Minimal Review-only anywhere wrapping, shrinkable items column and bounded metadata fixed all8 element-level cases at353/390. Three rendered preview regressions were RED then GREEN; final frontend177PASS, SQLite570PASS/2skip, typecheck/buildPASS. Independent review found no other important defect. No second review was requested: fixes were verified by tests and actual geometry.

Rulings: the old Foundation /review placeholder is retired in favor of the existing formal /reviews contract; an old placeholder bookmark now404s. The independent reviewer used actual CI/browser evidence under the read-only constraint, while the main executor performed those checks. These are technical integration choices, not new product rules.
Source PR42/43 are CLOSED with mergedAt=null and superseded by Stage2B comments; source branches preserved. PR40 remains OPEN / Draft / frozen at aea4348. Foundation/main remain unchanged. The final40-field execution report records the final documentation HEAD and PR states.

Final production fix HEAD:83224a669268cbfcdd969dde8f8c0f668de95f5a.
Final fix CI:https://github.com/kuangym29/qn-ai-workbench/actions/runs/38565618047 — SUCCESS; SQLite570/2skip/4766assertions, MySQL585/4926assertions plus gate13/122. Concurrency barrier polling makes assertion totals vary between successful runs.

V2-P3-P02B_DONE
