# QN AI 内容工作台 V2 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 建立 V2 的可运行基础闭环：灵感 → 选题 → 开始创作 → 选择渠道/内容形式 → 单一 Content Item + 多 ContentBranch → 内容库 / 进行中基础视图，同时保持现有 Auth / Project / Column / SourceReference 等可复用底座可用。

**Architecture:** V2 采用“方案 A”：保留 Laravel / Vue / Inertia / MySQL 及现有成熟通用能力，在现有仓库中新增 V2 业务层；开发期允许旧 V1 Production/Channel 表暂存以维持增量开发稳定，但 V2 最终验收前必须删除已被替代的旧业务代码和旧 schema。第一阶段只建立内容来源链与渠道内容分支，不提前实现图片批次、视频批次、AI 导入等后续子系统。

**Tech Stack:** Laravel 13, PHP, MySQL 8.4 LTS, Vue 3, Inertia 2, TypeScript 5.9, Vite 7

**Spec:** `docs/superpowers/specs/2026-10-12-qn-ai-workbench-v2-architecture-design.md`

## Global Constraints

- 历史 V1 业务数据允许清空；不为旧 ProductionTask / ChannelTask / Asset 关系设计长期兼容层。
- 前台服务一两人团队：能自动推导的状态不要求人工维护。
- 一个正式 Topic 进入创作后只对应一个 Content Item；渠道/内容形式通过 ContentBranch 扩展，不复制 Content Item。
- 公众号首批允许同时选择 `image_post` 与 `article`；视频号首批只允许 `video`；小红书仅预留渠道，不在本计划启用具体内容形式。
- Topic 前台状态仅 `pending`（待选）/`parked`（暂存）；“备用”是 `is_backup` 星标，不是状态。
- Content Item 前台核心字段仅 `title` / `core_message` / `story_line`。
- 业务来源链使用真实关系：Inspiration → Topic → ContentItem → ContentBranch；SourceReference 不承担业务来源链。
- ContentBranch 停止后保留历史；再次添加相同 channel+format 时恢复原分支，不新建重复分支。
- 后端必须继续按当前 Project 做作用域校验；跨 Project ID 返回 404，不依赖客户端 `project_id` 推断权限。
- 本计划不实现图片/视频批次、正式内容 Revision、AI 导入、审核中心业务逻辑；这些使用独立后续计划。

## Review Focus

- 同一 Topic 连续两次点击“开始创作”：第二次不得创建第二个 Content Item，返回 409 并指向已有 item。
- 非法 channel/format 组合（如 `wechat_channels + article`）：返回 422，不创建任何 branch。
- 任一跨 Project 的 Inspiration / Topic / ContentItem / ContentBranch ID：返回 404，且不产生副作用。
- 已停止的 branch 再次添加：恢复原 branch（清空 `stopped_at`），不得产生重复记录。
- 没有 Inspiration 的直接选题：来源显示为“直接创建选题”，不得伪造 Inspiration 或 SourceReference。

---

## Scope Decomposition

完整 V2 设计包含多个独立子系统，因此不在一个超大计划中一次实现。后续按独立计划推进：

1. **本计划：V2 Foundation & Creation Flow** — 灵感 / 选题 / Content Item / ContentBranch / 基础导航与进行中。
2. **Plan 2：Image Post + AI Import** — 小绿书 8 字段页面结构、Working Copy / 正式 Revision、结构化 AI 粘贴与 diff preview。
3. **Plan 3：Image Media Batch + Review** — 整批生图、试样/部分生成、单页返修、AI 任务快照、统一审核。
4. **Plan 4：Video Production** — 基础图片内嵌流程、完整旁白、动态说明、建议时长、自动旁白分配与时间轴。
5. **Plan 5：Article + Reusable Assets + Review Inbox** — 公众号图文、人物/场景存在性与回传、待我审核完善。
6. **Plan 6：V1 Cleanup + Fresh V2 Baseline** — 删除旧 Production/Channel/Asset 运行时代码、旧迁移/测试/文档，整理 fresh schema 并全量回归。

---

### Task 1: Add V2 Core Schema Without Breaking the Incremental Branch

**Files:**
- Create: `database/migrations/2026_10_12_000001_create_inspirations_table.php`
- Create: `database/migrations/2026_10_12_000002_upgrade_topics_for_v2.php`
- Create: `database/migrations/2026_10_12_000003_upgrade_content_items_for_v2.php`
- Create: `database/migrations/2026_10_12_000004_create_content_branches_table.php`
- Test: `tests/Feature/V2ContentCoreSchemaTest.php`

**Interfaces:**
- Produces table `inspirations(project_id, body, note, created_at, updated_at)`.
- Extends `topics` with nullable `inspiration_id`, `status` (`pending` default), `is_backup` (`false` default); keeps existing `content_column_id`, `title`, `description`.
- Extends `content_items` with nullable `core_message`, nullable `story_line`; enforces one Content Item per Topic with unique `topic_id` after the development database is cleared.
- Produces `content_branches(project_id, content_item_id, channel, format, stopped_at, created_at, updated_at)` with unique `(content_item_id, channel, format)`.

- [ ] **Step 1: Write the failing schema test**

Create `tests/Feature/V2ContentCoreSchemaTest.php` with tests that assert:

```php
Schema::hasColumns('inspirations', ['project_id', 'body', 'note']);
Schema::hasColumns('topics', ['inspiration_id', 'status', 'is_backup']);
Schema::hasColumns('content_items', ['core_message', 'story_line']);
Schema::hasColumns('content_branches', ['project_id', 'content_item_id', 'channel', 'format', 'stopped_at']);
```

Also insert duplicate `(content_item_id, channel, format)` and assert the database rejects it.

- [ ] **Step 2: Run the schema test and verify failure**

Run: `php artisan test tests/Feature/V2ContentCoreSchemaTest.php`

Expected: FAIL because V2 tables/columns do not exist.

- [ ] **Step 3: Implement the four migrations**

Use Project-scoped foreign keys consistent with the existing repository. `inspiration_id` is nullable so direct-created topics remain valid. Use strings for `status`, `channel`, and `format`; application enums own allowed values.

- [ ] **Step 4: Verify migrations on both SQLite test runtime and MySQL 8.4 gate**

Run:

```bash
php artisan migrate:fresh --env=testing
php artisan test tests/Feature/V2ContentCoreSchemaTest.php
./scripts/test-mysql.sh --filter V2ContentCoreSchemaTest
```

Expected: PASS; fresh schema creates V2 core without manual data backfill.

- [ ] **Step 5: Commit**

```bash
git add database/migrations tests/Feature/V2ContentCoreSchemaTest.php
git commit -m "feat: add v2 content foundation schema"
```

---

### Task 2: Add V2 Domain Enums, Models, Relations, and Factories

**Files:**
- Create: `app/Enums/TopicStatus.php`
- Create: `app/Enums/ContentFormat.php`
- Modify: `app/Enums/Channel.php`
- Create: `app/Models/Inspiration.php`
- Create: `app/Models/ContentBranch.php`
- Modify: `app/Models/Project.php`
- Modify: `app/Models/ContentColumn.php`
- Modify: `app/Models/Topic.php`
- Modify: `app/Models/ContentItem.php`
- Create: `database/factories/InspirationFactory.php`
- Create: `database/factories/ContentBranchFactory.php`
- Modify: `database/factories/TopicFactory.php`
- Modify: `database/factories/ContentItemFactory.php`
- Test: `tests/Feature/V2DomainModelTest.php`

**Interfaces:**
- `TopicStatus`: `Pending='pending'`, `Parked='parked'`.
- `Channel`: retain `WechatOfficial='wechat_official'`, `WechatChannels='wechat_channels'`; add `Xiaohongshu='xiaohongshu'`. Remove asset-role assumptions from Channel only when no V1 caller remains; until Plan 6, do not break existing V1 runtime methods.
- `ContentFormat`: `ImagePost='image_post'`, `Article='article'`, `Video='video'`.
- `ContentBranch::isStopped(): bool` returns `stopped_at !== null`.
- `ContentBranch::resume(): void` clears `stopped_at`; `stop(): void` sets it if null.
- `Topic` casts `status` to `TopicStatus` and `is_backup` to bool.

- [ ] **Step 1: Write failing model tests**

`tests/Feature/V2DomainModelTest.php` must assert:

```php
$this->assertInstanceOf(TopicStatus::class, $topic->status);
$this->assertFalse($topic->is_backup);
$this->assertSame($inspiration->id, $topic->inspiration?->id);
$this->assertSame($item->id, $topic->contentItem?->id);
$this->assertCount(2, $item->contentBranches);
```

Also assert `stop()` and `resume()` change only `stopped_at` and do not create/delete records.

- [ ] **Step 2: Run the model test and verify failure**

Run: `php artisan test tests/Feature/V2DomainModelTest.php`

Expected: FAIL with missing classes/relations/casts.

- [ ] **Step 3: Implement enums, models, relations, and factories**

Required relations:

```text
Project hasMany Inspirations / Topics / ContentItems / ContentBranches
Inspiration belongsTo Project; hasMany Topics
Topic belongsTo Project / ContentColumn / Inspiration?; hasOne ContentItem
ContentItem belongsTo Project / ContentColumn / Topic; hasMany ContentBranches
ContentBranch belongsTo Project / ContentItem
```

- [ ] **Step 4: Run tests**

Run:

```bash
php artisan test tests/Feature/V2DomainModelTest.php tests/Feature/V2ContentCoreSchemaTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Enums app/Models database/factories tests/Feature/V2DomainModelTest.php
git commit -m "feat: add v2 content domain models"
```

---

### Task 3: Build the V2 Inspiration, Topic, Start-Creation, and Branch APIs

**Files:**
- Create: `app/Http/Controllers/InspirationController.php`
- Modify: `app/Http/Controllers/TopicController.php`
- Modify: `app/Http/Controllers/ContentItemController.php`
- Create: `app/Http/Controllers/ContentBranchController.php`
- Create: `app/Http/Controllers/ContentStartController.php`
- Create: `app/Http/Requests/InspirationRequest.php`
- Modify: `app/Http/Requests/TopicRequest.php`
- Modify: `app/Http/Requests/ContentItemRequest.php`
- Create: `app/Http/Requests/StartContentRequest.php`
- Create: `app/Http/Requests/ContentBranchRequest.php`
- Create: `app/Http/Resources/InspirationResource.php`
- Modify: `app/Http/Resources/TopicResource.php`
- Modify: `app/Http/Resources/ContentItemResource.php`
- Create: `app/Http/Resources/ContentBranchResource.php`
- Create: `app/Services/StartContentService.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/V2ContentFlowApiTest.php`

**Interfaces:**

V2 API paths are intentionally Project-scoped but no longer deeply nested by Column/Topic:

```text
GET    /api/projects/{project}/inspirations
POST   /api/projects/{project}/inspirations
PATCH  /api/projects/{project}/inspirations/{inspiration}

GET    /api/projects/{project}/topics
POST   /api/projects/{project}/topics
PATCH  /api/projects/{project}/topics/{topic}
POST   /api/projects/{project}/topics/{topic}/start

GET    /api/projects/{project}/items
GET    /api/projects/{project}/items/{item}
PATCH  /api/projects/{project}/items/{item}

POST   /api/projects/{project}/items/{item}/branches
POST   /api/projects/{project}/items/{item}/branches/{branch}/stop
```

`StartContentService::start(Topic $topic, array $branchSelections): ContentItem`:
- rejects if Topic already has a ContentItem with HTTP 409 at controller level;
- creates ContentItem title from Topic title;
- validates the whole branch set before starting the transaction;
- creates all selected branches atomically.

Allowed initial branch pairs:

```text
wechat_official + image_post
wechat_official + article
wechat_channels + video
```

`xiaohongshu` is not selectable yet.

Adding an existing stopped `(channel, format)` resumes it; adding an existing active pair returns the existing branch idempotently.

- [ ] **Step 1: Write API tests first**

`tests/Feature/V2ContentFlowApiTest.php` must cover:

- create/update Inspiration;
- create Topic with nullable `inspiration_id` and required `content_column_id`;
- Topic statuses only `pending|parked` and backup star boolean;
- start Topic with one or multiple valid branches;
- duplicate start returns 409 and leaves one ContentItem;
- invalid channel/format returns 422 and creates neither item nor partial branches;
- add later branch;
- stop branch and add same pair again resumes same ID;
- direct-created Topic has `inspiration = null`;
- cross-Project IDs return 404.

- [ ] **Step 2: Run the API test and verify failure**

Run: `php artisan test tests/Feature/V2ContentFlowApiTest.php`

Expected: FAIL because endpoints/services are missing.

- [ ] **Step 3: Implement validation and resources**

Responses remain wrapped using the repository's existing `{ "data": ... }` convention. Keep server-side ProjectContext checks; route IDs alone are never trusted.

- [ ] **Step 4: Implement `StartContentService` transaction and branch add/stop semantics**

Use a database transaction and lock the Topic row before checking/creating the one ContentItem. This prevents double-click or concurrent requests from creating duplicate items.

- [ ] **Step 5: Run API and concurrency-relevant tests**

Run:

```bash
php artisan test tests/Feature/V2ContentFlowApiTest.php
./scripts/test-mysql.sh --filter V2ContentFlowApiTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http app/Services/StartContentService.php routes/web.php tests/Feature/V2ContentFlowApiTest.php
git commit -m "feat: add v2 inspiration and creation APIs"
```

---

### Task 4: Add V2 Frontend API Clients, Types, and Primary Navigation

**Files:**
- Create: `resources/js/api/inspirations.ts`
- Create: `resources/js/api/contentBranches.ts`
- Modify: `resources/js/api/topics.ts`
- Modify: `resources/js/api/contentItems.ts`
- Modify: `resources/js/api/types.ts`
- Modify: `resources/js/layouts/AdminLayout.vue`
- Modify: `routes/web.php`
- Create: `resources/js/pages/InProgress/Index.vue`
- Create: `resources/js/pages/Inspirations/Index.vue`
- Create: `resources/js/pages/Topics/Pool.vue`
- Create: `resources/js/pages/ContentLibrary/Index.vue`
- Create: `resources/js/pages/ReviewInbox/Index.vue`
- Test: `tests/Feature/V2WorkspaceRouteTest.php`

**Interfaces:**

Primary navigation for a selected Project:

```text
进行中
灵感
选题
内容库
待我审核
项目 / 栏目设置
```

Inertia routes:

```text
/projects/{project}/in-progress
/projects/{project}/inspirations
/projects/{project}/topics
/projects/{project}/content
/projects/{project}/review
```

The Review page is only a shell in this plan; real review aggregation belongs to later plans.

- [ ] **Step 1: Write route tests**

`tests/Feature/V2WorkspaceRouteTest.php` asserts every route requires auth, renders the intended Inertia component, and carries `projectId`.

- [ ] **Step 2: Run route test and verify failure**

Run: `php artisan test tests/Feature/V2WorkspaceRouteTest.php`

Expected: FAIL with missing routes/components.

- [ ] **Step 3: Add TypeScript API contracts and clients**

Types must include `Inspiration`, `Topic.status`, `Topic.is_backup`, `ContentItem.core_message`, `ContentItem.story_line`, `ContentBranch.channel`, `ContentBranch.format`, and `ContentBranch.stopped_at`.

- [ ] **Step 4: Add routes, page shells, and update AdminLayout navigation**

Do not expose `ContentBranch`, `Revision`, or other technical object names in user-visible labels.

- [ ] **Step 5: Verify PHP routes, TypeScript, and bundle**

Run:

```bash
php artisan test tests/Feature/V2WorkspaceRouteTest.php
npm run typecheck
npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js routes/web.php tests/Feature/V2WorkspaceRouteTest.php
git commit -m "feat: add v2 workspace navigation"
```

---

### Task 5: Implement Inspiration Pool, Topic Pool, and Start-Creation UI

**Files:**
- Modify: `resources/js/pages/Inspirations/Index.vue`
- Modify: `resources/js/pages/Topics/Pool.vue`
- Create: `resources/js/components/StartCreationDialog.vue`
- Create: `resources/js/components/SourceTrail.vue`
- Modify: `resources/js/api/inspirations.ts`
- Modify: `resources/js/api/topics.ts`
- Modify: `resources/js/api/contentItems.ts`
- Test: `tests/Feature/V2StartCreationContractTest.php`

**Interfaces:**

Inspiration quick entry:

```text
灵感内容（主要输入）
补充备注（可选）
```

Topic pool UI:
- `待选` / `暂存` two-state filter;
- star toggle for `备用`;
- `开始创作` button only when no Content Item exists;
- source weak display via `SourceTrail`.

StartCreationDialog exact initial options:

```text
☑ 微信公众号
   ☑ 图片文章（小绿书）
   □ 图文文章

☑ 微信视频号

□ 小红书（预留，disabled）
```

Validation: at least one enabled content format must be selected.

- [ ] **Step 1: Add server-contract assertions for UI payload expectations**

`tests/Feature/V2StartCreationContractTest.php` asserts Topic API exposes `has_content_item`/`content_item_id`, and start response includes branch labels/keys needed by the UI.

- [ ] **Step 2: Run test and verify failure**

Run: `php artisan test tests/Feature/V2StartCreationContractTest.php`

Expected: FAIL until resources expose the fields.

- [ ] **Step 3: Implement quick Inspiration creation and Topic list controls**

No required classification fields. Column selection is required only when creating a Topic, not when capturing an Inspiration.

- [ ] **Step 4: Implement StartCreationDialog and submit one atomic start request**

On success, navigate to the Content Item detail page introduced in Task 6; on 409, navigate to the existing Content Item returned by the API rather than offering a duplicate retry.

- [ ] **Step 5: Verify contracts and frontend build**

Run:

```bash
php artisan test tests/Feature/V2StartCreationContractTest.php tests/Feature/V2ContentFlowApiTest.php
npm run typecheck
npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Resources resources/js tests/Feature/V2StartCreationContractTest.php
git commit -m "feat: add v2 inspiration and topic workflow"
```

---

### Task 6: Implement Content Library, Content Detail, Branch Management, and Basic In-Progress Matrix

**Files:**
- Modify: `resources/js/pages/ContentLibrary/Index.vue`
- Create: `resources/js/pages/ContentLibrary/Show.vue`
- Modify: `resources/js/pages/InProgress/Index.vue`
- Create: `resources/js/components/ContentBranchCard.vue`
- Create: `resources/js/components/AddBranchDialog.vue`
- Create: `app/Http/Controllers/InProgressController.php`
- Create: `app/Http/Resources/InProgressItemResource.php`
- Modify: `routes/web.php`
- Create: `resources/js/api/inProgress.ts`
- Test: `tests/Feature/V2InProgressApiTest.php`
- Test: `tests/Feature/V2ContentLibraryRouteTest.php`

**Interfaces:**

Content detail shows only shared mother-content inputs prominently:

```text
title
core_message
story_line
来源 ›
```

Branches appear as user labels, for example:

```text
微信公众号 · 图片文章（小绿书）
微信公众号 · 图文文章
微信视频号 · 视频
```

AddBranchDialog uses the same allowed pair rules as StartCreationDialog. Stopping a branch asks for confirmation and preserves the card/history; stopped cards are visually weak and offer “恢复”.

`GET /api/projects/{project}/in-progress` returns one row per Content Item with active branch summaries. Initial `next_action` logic is deliberately small:

```text
if core_message/story_line missing -> 完善内容
else if any active branch exists -> 开始制作
else -> 添加制作渠道
```

Later media/review plans replace `开始制作` with precise derived next actions.

- [ ] **Step 1: Write InProgress API and route tests**

Assert:
- one row per Content Item, never one row per branch;
- channel columns can show multiple formats for 公众号;
- stopped branches do not count as active progress but remain available on item detail;
- missing core/story produces `完善内容`;
- cross-project item route is 404.

- [ ] **Step 2: Run tests and verify failure**

Run:

```bash
php artisan test tests/Feature/V2InProgressApiTest.php tests/Feature/V2ContentLibraryRouteTest.php
```

Expected: FAIL with missing controller/page/routes.

- [ ] **Step 3: Implement Content Library list/detail and shared-content edit**

`ContentItemRequest` allows only `title`, `core_message`, `story_line` in this UI path; channel-specific copy is not added here.

- [ ] **Step 4: Implement branch add/stop/resume UI**

Reuse the same channel-format metadata source used by StartCreationDialog; do not duplicate allowed pair definitions in two components.

- [ ] **Step 5: Implement basic InProgress aggregation and matrix**

Columns: `内容 | 公众号 | 视频号 | 小红书 | 下一步`. Keep the matrix compact; no Kanban, owner, deadline, or Gantt metadata.

- [ ] **Step 6: Verify backend and frontend**

Run:

```bash
php artisan test tests/Feature/V2InProgressApiTest.php tests/Feature/V2ContentLibraryRouteTest.php tests/Feature/V2ContentFlowApiTest.php
npm run typecheck
npm run build
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http resources/js routes/web.php tests/Feature/V2InProgressApiTest.php tests/Feature/V2ContentLibraryRouteTest.php
git commit -m "feat: add v2 content library and progress view"
```

---

### Task 7: Foundation Integration Review, Documentation Update, and Guardrails

**Files:**
- Modify: `docs/ARCHITECTURE.md`
- Modify: `docs/DATABASE_BASELINE.md`
- Modify: `README.md`
- Create: `docs/V2_FOUNDATION_ACCEPTANCE.md`
- Test: existing + all V2 foundation tests

**Interfaces:**
- Documents the V2 source chain, ContentBranch allowed pairs, simplified V2 routes, and the fact that ProductionTask/ChannelTask are legacy and scheduled for deletion in Plan 6.
- Explicitly states that old V1 business data is disposable and no production migration/backfill is promised.

- [ ] **Step 1: Run focused foundation suite before documentation edits**

Run:

```bash
php artisan test \
  tests/Feature/V2ContentCoreSchemaTest.php \
  tests/Feature/V2DomainModelTest.php \
  tests/Feature/V2ContentFlowApiTest.php \
  tests/Feature/V2WorkspaceRouteTest.php \
  tests/Feature/V2StartCreationContractTest.php \
  tests/Feature/V2InProgressApiTest.php \
  tests/Feature/V2ContentLibraryRouteTest.php
npm run typecheck
npm run build
```

Expected: PASS.

- [ ] **Step 2: Run full PHP test suite and classify failures**

Run: `php artisan test`

Expected: all reusable V1 tests still pass at this incremental stage. If a failure is caused only by an intentionally superseded V1 assumption, do **not** patch V2 to satisfy it; record it for Plan 6 cleanup and keep the branch green by updating/removing only the superseded test when its old runtime path has already been replaced.

- [ ] **Step 3: Run MySQL 8.4 verification**

Run: `./scripts/test-mysql.sh`

Expected: PASS with no new schema/locking failures.

- [ ] **Step 4: Update architecture/database/readme and write acceptance checklist**

`docs/V2_FOUNDATION_ACCEPTANCE.md` must include manual acceptance for:

```text
快速记灵感
→ 生成/直接创建选题
→ 待选/暂存 + 备用星标
→ 开始创作
→ 同时选公众号小绿书 + 视频号
→ 只得到一个 Content Item
→ 后续再加公众号图文
→ 停止/恢复某个分支
→ 在“进行中”看到一行母内容和各渠道摘要
```

- [ ] **Step 5: Self-review against the design spec**

Check specifically:
- no duplicate user entry fields were introduced;
- no extra Topic/Branch statuses were added;
- no channel-specific content was placed on ContentItem;
- no SourceReference was used as business lineage;
- no old ProductionTask/ChannelTask dependency was added to V2 endpoints;
- Xiaohongshu remains reserved/disabled rather than partially implemented.

- [ ] **Step 6: Commit**

```bash
git add README.md docs
git commit -m "docs: lock v2 foundation baseline"
```

---

## Plan Self-Review Results

**Spec coverage for this slice:** covers Inspiration, Topic simplification, one Content Item, channel/content-format selection, ContentBranch, later branch addition, branch stop/history, primary navigation, content library, basic InProgress, Project scoping, and light UI. Image-post page content, AI import, MediaBatch, video, article production, reusable character/scene assets, and real Review Inbox are intentionally deferred to their own plans.

**Type consistency:** `TopicStatus`, `ContentFormat`, `Channel`, `ContentBranch`, and API field names are defined once and reused across tasks. `core_message` / `story_line` are the canonical ContentItem names throughout this plan.

**Review-focus coverage:** duplicate start, invalid format pair, cross-project access, branch resume, and direct-created topic are all assigned explicit API tests.

**Scope/proportion:** this plan produces one independently useful working slice and does not attempt to encode later image/video production internals prematurely.
