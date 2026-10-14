# I03 Review integration validation

Integration base: 223e2ec3400364620cefd1e966dfafeed76b3d56.
Backend source: bdbe5c0dc037b68ac639f60132483a07c08fbfa2.
Frontend source: cd379872312bfdd55a010f7f188d4f23eaf7b618.

C04 diverged from Foundation; only its Review-owned files were transplanted. Its branch history was not merged.

Production uses the backend data envelope and parser-free Review contract. Decisions reload the server pending list. Parent row locks serialize duplicate pending creation and concurrent decisions. Real MySQL CI at the RED commit reproduced both races before these locks were implemented.

Local validation recorded: SQLite 388 passed, 2 MySQL-only tests skipped; frontend 33 passed; typecheck and build passed. Browser verified text/lines/items/null previews, required return comment, approved/changes_requested decisions, empty pending list, actual concurrent-decision 409, missing-detail 404, and cross-project page 404.

The original Foundation layout overflows at 353px. The user explicitly accepted keeping I03 within its original boundary and deferring this viewport acceptance to Stage2B with H01. AdminLayout must not be changed in I03.

Final MySQL GREEN CI is pending. Initial runs were rejected before any jobs because GitHub required email verification. The user has now confirmed verification; a new documentation commit triggers a fresh validation run because the rejected runs cannot be retried. PR31 remains open until final validation succeeds. No Foundation/main merge is authorized.