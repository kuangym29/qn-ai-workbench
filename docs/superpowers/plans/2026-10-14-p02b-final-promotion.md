# Stage2B Final Promotion Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. No parallel integration writers.

**Goal:** Combine frozen I06B/P02A and I03 without changing product scope, then validate the complete system.

**Architecture:** Start at I06B `2358b4f459d52d7d56b4cf1f6753a6eee47ca57a` and merge I03 `f059d4ab3d6a10ac28dd540581e5bf6d5d05a7f8`, preserving both histories. Resolve shared routes and Project relations by composing their actual contracts. Keep production providers and mobile shell inherited from I06B/P02A.

**Tech Stack:** Laravel/PHP, Vue/TypeScript, SQLite, MySQL 8.4, Vite.

**Spec:** User task V2-P3-P02B-FINAL-PROMOTION (attachment b4abbc22-2f58-4e7f-9b82-b80e15c9a544).

## Global Constraints

- Own branch `work/v2-p3-promotion-stage2b`; no Foundation/main merge.
- Review envelope `{data:...}`; ReusableAsset workspace has only characters/scenes at top level.
- AssetNameSource binds ReusableAssetNameSource; default reference reader is EmptyAssetReferenceReader. No inferred references or last_used_at writes from read/copy/queued run.
- No Review producer, generation, asset CRUD, upload, notifications or new product structure.
- Real 353px/390px acceptance is required now; no global overflow clipping workaround.
- Close source PR42/43 only after all validation passes; preserve PR40 and other frozen PRs.

## Review Focus

- Duplicate route declarations hidden by Laravel's route collection replacement.
- Project relation composition losing existing Foundation or asset associations.
- Mixed envelopes between Review and other V2 workspaces.
- ImageBatch DI drift and accidental asset usage writes.
- Long Review content and controls at 353px, including drawer and comment editing.

### Task 1: Verify source and baseline
- [ ] Verify source SHAs, P02A ancestry and isolated worktree.
- [ ] Run I06B baseline SQLite suite before integration.

### Task 2: Compose shared contracts
Files: `routes/web.php`, `app/Models/Project.php`, `tests/Feature/V2/PromotionStage2BIntegrationTest.php`; providers only if a real conflict requires it.
- [ ] Add route smoke/uniqueness, Project relation and container binding regression tests.
- [ ] Run RED on I06B (Review is absent).
- [ ] Merge I03; inspect every conflict; retain all six module route families and both relation sets.
- [ ] Run new tests GREEN and targeted backend/frontend contracts; commit integration.

### Task 3: Complete automated verification
- [ ] Run all frontend test files (excluding browser harness entrypoints), typecheck, build and diff check.
- [ ] Run full SQLite, all migration cycles and full MySQL 8.4 gate including Review concurrency.
- [ ] Inspect production imports for mock fallback and forbidden producers; publish Draft staging PR for CI.

### Task 4: Real browser matrix
- [ ] Start isolated Laravel with real Vite build and disposable seeded SQLite.
- [ ] Exercise ImagePost, Article, Video, ImageBatch, ReusableAssets and Review flows specified by the user.
- [ ] Verify Content Library/Detail and all business pages at353px; Review/assets at390px; shell at768/1024/1440; drawer open/close/overlay/ESC.
- [ ] Record actual document/client widths and expected versus unexpected console errors; fix only reproduced integration bugs using RED→GREEN.

### Task 5: Review and freeze
- [ ] Conduct a fresh review against all20 user focus items; resolve important findings with regression tests.
- [ ] Push final branch, verify final CI and clean status, record40-field report.
- [ ] Close PR42/43 as superseded without merge; keep PR40 open/draft/frozen. Stop.
