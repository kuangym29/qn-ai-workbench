# I03 Review integration validation

Integration base: 223e2ec3400364620cefd1e966dfafeed76b3d56.
Backend source: bdbe5c0dc037b68ac639f60132483a07c08fbfa2.
Frontend source: cd379872312bfdd55a010f7f188d4f23eaf7b618.

C04 diverged from Foundation; only its Review-owned files were transplanted. Its branch history was not merged.

Production uses the backend data envelope and parser-free Review contract. Decisions reload the server pending list. Parent row locks serialize duplicate pending creation and concurrent decisions. Real MySQL CI at the RED commit reproduced both races before these locks were implemented.

Local validation recorded: SQLite 388 passed, 2 MySQL-only tests skipped; frontend 33 passed; typecheck and build passed. Browser verified text/lines/items/null previews, required return comment, approved/changes_requested decisions, empty pending list, actual concurrent-decision 409, missing-detail 404, and cross-project page 404.

The original Foundation layout overflows at 353px. The user explicitly accepted keeping I03 within its original boundary and deferring this viewport acceptance to Stage2B with H01. AdminLayout must not be changed in I03.

## Final verified results

Business code HEAD: 665e05103f6c71ff155a794a8f669295a464ce8b.
CI: https://github.com/kuangym29/qn-ai-workbench/actions/runs/38540285121 — SUCCESS, PHP and MySQL jobs both passed.

- SQLite: 388 passed, 2 MySQL-only skips, 3834 assertions.
- MySQL 8.4.11: full suite 403 tests / 3988 assertions; gate-focused suite 13 tests / 122 assertions.
- Independent local MySQL 8.4.11 on disposable port 3401: concurrent creation and decisions both passed (2 tests / 238 assertions).
- Frontend: 33 passed. Typecheck and production build passed.
- Migration fresh/reset/rollback/re-migrate are exercised by ReviewMigrationRollbackTest, included in SQLite and MySQL suites; no step=N dependency.
- Browser: text/lines/items/null, approve/return, required comment, pending refresh/empty state, real 409, real missing-detail 404, cross-project 404, unauthenticated login redirect, and reload passed. Final page console warnings/errors: none. Busy guards and stale response races have frontend regression tests.
- Fresh independent review: no outstanding important defect. Production has no mock fallback or enum aliases. B03 ancestry retained; C04 history not merged.

The concurrency test worker itself initially retained the copied connection name, causing hydrated models to use a second connection and self-lock. The worker now removes that name and checks model/query connection identity; the original 25-second timeout is retained. No production workaround was added.

GitHub initially rejected workflows pending email verification; after account verification and connection refresh, the final run above executed and passed. PR42 remains Draft. PR31 is superseded by this integration and is to be closed without merging. No Foundation/main merge is authorized.