# V2-P2-I01 integration execution ledger

Start: `1fe589f8be6461ab6010b13ce5637699a3d647f8`; branch `work/v2-p2-image-post-integration`.

## Sources and boundaries

- A01 `f268fc30b4c2d3408eeab0aa93ef310de028597c`: accepted query cleanup and in-page stop confirmation retained.
- B01/R1 `1647666f1a888f7dd96bd0a1cdf831138e2d4037` (parent `e7aedacc8f713a426805a7836d2c115d721d5fca`): original objects verified and merged; complete backend ancestry retained.
- C01 `ae6c352bb1958fe75340fe1ca6bbd798c5cdc9f6`: original frontend source retained in history, temporary implementation replaced in production.
- No main/foundation ref changes, C02/C03, Review, MediaBatch or video production integration.
- Git transport to github.com:443 unavailable; GitHub object API used with SHA verification. Publication uses GitHub Git API with all three source commits as parents, preserving source histories.

## Rulings and TDD

- Official page resolves project/item/branch on server and supplies real column/source provenance. Entry tests RED (3 failed), GREEN (3 passed, 25 assertions).
- Backend owns parsing, normalization, validation, diff and persistence. Frontend only sends raw, renders preview/warnings, confirms apply, reloads GET. Manual PUT performs upsert, never deletes omitted pages.
- Frontend transport/state tests RED then GREEN: 9 tests, including cancel/no writes, immutable preview raw, authoritative reload, apply/GET failure handling, fixed-cover omission, manual-draft guard and pending-preview editing race.
- Backend regression RED in two batches of 3. GREEN image-post: 50 tests / 194 assertions. Fixed empty optional shared fields, invalid titles/page numbers, multiline text, unchanged AI versions and serialized page/version writes via parent-row locking.
- Partial import means selected page blocks only; other pages remain unchanged. Existing semantics of omitted fields inside an imported page remain unchanged.
- Removed DEV Vue previews, standalone HTML/TS entry, frontend parser/business diff/memory apply and obsolete C01 DEV instructions. No field-name adapter remains.

## Verification checkpoint

- PHP full / SQLite in-memory: 344 tests / 3667 assertions PASS (55.87s).
- Typecheck PASS; frontend 9 tests PASS; production build PASS before final browser run.
- Integration code/tree commit `0c44cc1f26d5c6470abd88e1c2204bd008bb917d` independently tested in [CI run 38340368862](https://github.com/kuangym29/qn-ai-workbench/actions/runs/38340368862): PHP/SQLite 344 tests / 3667 assertions; MySQL 8.4.11 full gate 357 tests / 3739 assertions. Both jobs SUCCESS.
- Fresh whole-branch review found two editor races (editing during Preview request and story-line editing during PUT). Fixed controls and state guards; added pending-request regression test. Reviewer rechecked: no outstanding actionable findings.
- Real browser checklist passed; see `docs/V2_P2_I01_BROWSER_ACCEPTANCE.md`.
- Backend commit ancestry verified before closing PR #28 without merge, with comment `superseded by V2-P2-I01 integration`. WorkBuddy branch retained.
- [Draft integration PR #29](https://github.com/kuangym29/qn-ai-workbench/pull/29) remains unmerged. Only integration ref published. Final closeout documentation is a subsequent commit with no production changes.
