# V2 Promotion Stage 2A

Stage1 source: `71196ebe916f73c3a4b38f99e21304df0aca63a9` (#38).
H01 source: `9fd32ef0ce7f3cd670d8dd3c6225f390effa1324` (#39).
Branch: `work/v2-p3-promotion-stage2a`, based on the frozen Stage1 head.

H01 was checked against Foundation `223e2ec3400364620cefd1e966dfafeed76b3d56`: exactly two files, AdminLayout.vue and tests/frontend/layout/narrowLayout.mjs. No backend, database, route or business import was included. H01 was merged with both parent histories retained. The source branches/PRs remain frozen and open.

## Integration corrections

1. Removed one VideoEditor scoped CSS declaration hiding aside below640px. It was the earlier I04 local workaround and blocked the new global mobile drawer. No Video data or behavior contract changed. Regression first failed on the old declaration, then passed.
2. Closed mobile navigation now has visibility:hidden, overridden to visible at md desktop, so offscreen controls cannot receive keyboard focus. Opening focuses the first enabled navigation action; closing/ESC returns focus to the menu button. The visibility regression first failed then passed; actual browser focus was checked.
3. Browser reproduced a wrapped353px topbar105px high covering the drawer's first navigation row. ResizeObserver measures the actual header height and offsets only the fixed mobile drawer. Desktop remains a static240px sidebar. Observer is disconnected on unmount. Browser confirmed drawer top=header bottom and the first navigation action is accessible.

No html/body overflow-x:hidden, global clipping, business route rewrite or schema change was added. Existing Article/Video/ImageBatch contracts remain those of Stage1.

## Verification

- Backend targeted:135 tests/737 assertions.
- SQLite full:471 tests/4285 assertions; includes combined fresh/reset/rollback/re-migrate without step counts.
- Frontend:109 tests passing, including Article/Video/ImageBatch and five new shell regression tests. Typecheck and Vite production build pass; existing chunk-size advisory remains.
- Final-head MySQL8.4 and CI results are recorded on the Draft PR and final closeout report.
- Independent read-only fresh review found keyboard focus and possible header overlap; fixes were re-reviewed with no remaining important static issue. Main browser verification confirmed both fixes.

Real browser used dedicated SQLite/session and Laravel port8792 with Vite production assets. No mock API or simulated writes:

| Page |353|390|768|1024|1440|
|---|---|---|---|---|---|
|Content Library / content detail|PASS|PASS|PASS|PASS|PASS|
|Article|PASS|PASS|PASS|PASS|PASS|
|Video|PASS|PASS|PASS|PASS|PASS|
|ImageBatch|PASS|PASS|PASS|PASS|PASS|

All measured document scrollWidth equals clientWidth. Narrow main width equals viewport width. Desktop sidebar is static/visible240px with main widths528/784/1200. Computed html/body overflow-x is visible, not hidden. Ten page/width drawer scenarios (Content Library, detail, Article, Video, ImageBatch ×353/390) verified opening, toggle close, real backdrop click, ESC and focus entry/return.

Article: formal entry/GET, edit/save/reload, backend preview/apply/reload. Video: edit/save/reload,4.5s source unit and10.5s server timeline, real source modification warning and keep. ImageBatch: draft save/reload, full/trial/partial all queued, server task copied and read back, history with real times, source keep/regenerate after formal image-post preview/apply. Final browser console error/warn list empty. The carried H01 standalone Playwright script remains unchanged; its browser cases were rerun through the supported in-app browser interface, with stronger business-page coverage and a separate Node shell regression suite.

Evidence: `E:/CODEX_nopro_file/p02a-browser-evidence.json`; screenshots `p02a-image-batch-353.png`, `p02a-drawer-353.png`, `p02a-library-768.png`, `p02a-library-1024.png`, `p02a-library-1440.png` in the same directory. Temporary viewport is reset before delivery.

No I03, I06A, B06, ReusableAsset binding, Foundation/main promotion, or original PR closure. Stage2B awaits separately frozen inputs and explicit instructions. Stop after publishing this Draft PR and verifying final-head CI.
