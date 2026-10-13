# V2-P2-I01 browser acceptance

Date: 2026-10-13. Code commit: `0c44cc1f26d5c6470abd88e1c2204bd008bb917d`.
Start: `1fe589f8be6461ab6010b13ce5637699a3d647f8`. Branch: `work/v2-p2-image-post-integration`.

## Actual browser environment

Codex in-app Chromium, real Laravel loopback server `http://127.0.0.1:8787`, built production Vue bundle, isolated SQLite `foundation-browser.sqlite`. Disposable acceptance user and projects A/B; no production records used. Desktop 1280×900 and original narrow panel inspected.

## Required checklist

| # | Operation | Observed result |
|---|---|---|
| 1 | Content library → content item → 编辑图片文章 | One official branch-aware entry opens editor |
| 2 | GET official data | Article, real column/source, shared fields and ordered pages loaded |
| 3 | Edit 核心表达 | Entered `I01 浏览器手动保存核心表达` |
| 4 | Edit P4 | Entered `I01 手动保存 P4`, completed page edit |
| 5 | 保存整篇 | Real PUT succeeded and GET reloaded |
| 6 | Reload browser | Both manual changes persisted |
| 7 | Paste whole AI text | Article core + P1/P3/P4/P8/P9 blocks sent as raw |
| 8 | 解析并预览 | Backend Preview returned without changing formal data |
| 9 | added | 新增 P3 shown with all page fields |
| 10 | modified | Core and P4 before/after shown |
| 11 | unchanged | 未变化 P1 shown |
| 12 | Cancel then reload | Original manual core/P4 and 4-page count retained; P3 absent |
| 13 | Preview again → 确认导入 | Real Apply persisted and GET refreshed |
| 14 | Reload browser | AI core/P3/P4 persisted; now 5 pages |
| 15 | Only P4 raw block → Preview/Apply/reload | Only P4 changed; P1/P2/P3/P8 and article fields retained; multiline text and colon dialogue preserved |
| 16 | Existing P8 fixed_back_cover | 固定内容 · 只读, no edit control |
| 17 | Import attack on P8 and new fixed P9 | Two backend warnings shown; Apply left P8 unchanged and did not create P9 |
| 18 | Switch to B, open A editor URL | B library empty; A URL returned 404 |
| 19 | A non-image_post branch at editor URL | 422, `仅图片文章分支支持此编辑器。` |
| 20 | 返回篇目 / browser back / forward | Returned to mother item, back reopened editor with current data, forward restored mother item |

Source details expanded: actual inspiration and topic shown, not a substitute SourceReference.

Direct browser navigation to an `/api/` URL was blocked by the browser client; this was not retried or bypassed. Real page interactions successfully used the same APIs. API cross-project 404/non-image-post 422 and manual/AI fixed-cover protections were additionally verified by PHP feature tests on SQLite and MySQL.

## Contract and cleanup

Official page `/projects/{project}/content/{item}/branches/{branch}/image-post`.
Official API `/api/projects/{project}/items/{item}/branches/{branch}/image-post`:

- GET returns `{item, branch, pages}` directly.
- PUT accepts shared fields plus page upserts, returns status/warnings; changes alone create page versions; omitted pages remain.
- POST `/preview` takes `{raw}`, returns `{item:{changed,fields},pages:{added,modified,unchanged},warnings}` directly and writes nothing.
- POST `/apply` takes the previewed raw snapshot (optional backend `page_nos` selection), parses/validates again, persists versioned changes, then frontend re-GETs.
- Internal `core_message` only; UI 核心表达. No compatibility adapter or frontend parser/business diff/apply remains.
- Removed both DEV Vue previews, standalone TS/HTML entry, DEV route and obsolete C01 DEV instructions.
- Manual save omits read-only cover; backend independently rejects attempts to add/modify it.
- Unsaved manual edits must be saved before AI Preview. Busy parsing/save/apply disables editing to prevent discarded drafts.

## Verification and closeout

- PHP / SQLite full: 344 tests, 3667 assertions PASS locally and CI.
- MySQL 8.4.11 gate: 357 tests, 3739 assertions PASS, including schema assertions, rollback and full suite.
- Frontend: 9 tests PASS (`node --experimental-strip-types --test tests/frontend/v2/imagePostCore.test.ts tests/frontend/v2/imagePostEditor.test.mjs`).
- Typecheck, production build and `git diff --check`: PASS.
- Fresh whole-branch review: no outstanding actionable findings after race fixes.
- PR #28 CLOSED, not merged, superseded by integration draft #29. B01 original commits retained in history; WorkBuddy branch preserved.
- No main/foundation merge, no C02/C03 or other prohibited scope.

Screenshots saved outside repository: `i01-ai-preview.jpg` and `i01-editor-accepted.jpg` in the task outputs directory.

Result: **V2-P2-I01_DONE**. Stop after publication; integration draft remains unmerged for user-controlled next step.
