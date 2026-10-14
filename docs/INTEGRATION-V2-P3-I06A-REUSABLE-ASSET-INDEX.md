# 集成 · V2-P3-I06A 人物 / 场景复用资产索引（前后端正式集成）

> 任务编号：V2-P3-I06A-REUSABLE-ASSET-INTEGRATION
> 集成分支：`doubao/v2-reusable-asset-index-integration`（Draft PR → `codex/v2-foundation`，不 merge）
> 基线：`codex/v2-foundation` @ `223e2ec3400364620cefd1e966dfafeed76b3d56`
> 后端来源：B06 `workbuddy/v2-reusable-asset-index-backend` @ `734988595a541e7982cda5aef75bb065b5f43080`（PR #35）
> 前端来源：C06 `doubao/v2-reusable-asset-index-frontend` @ `6ca2f2ab3b346ccc152b3c651dcc5c0c5a031ab7`
> 范围：只做 Reusable Asset 自身集成；**不接 ImageBatch / AssetNameSource binding（留 I06B）**。

## 1. 响应形状决议（核心收口）

B06 后端返回的就是 **ReusableAssetWorkspace 本体**，不是 `{ data: { characters, scenes } }`：

```
GET /api/projects/{project}/reusable-assets
  → 200 { characters: [...], scenes: [...] }
```

- 前端 `assetIndexApi.loadWorkspace()` 读 `res.data as ReusableAssetWorkspace`（**不读 `res.data.data`**）。
- 双端测试锁死：
  - 后端 `ReusableAssetApiTest::test_response_body_is_the_workspace_itself_without_data_envelope`
    （断言顶层键恰为 `['characters','scenes']`，无 `data` 键）。
  - 前端 `response shape 锁：前端绝不读取 data.data` + `assetIndexApi 源码中不存在 data.data 解包`。
- 理由：V2 Video / ImageBatch 正式 API 均以 response body 本体作为 workspace 真源，不为单模块加 envelope。
- 未修改 B06 Controller 去迎合 envelope。

## 2. 正式合同（冻结，前后端一致）

```ts
type ReusableAssetType = 'character' | 'scene';
type ReusableAssetStatus = 'existing' | 'missing';

interface ReusableAssetItem {
  id: number | string;
  type: ReusableAssetType;
  name: string;
  status: ReusableAssetStatus;
  thumbnail_url: string | null;
  description: string | null;
  last_used_at: string | null;
}
interface ReusableAssetWorkspace {
  characters: ReusableAssetItem[];
  scenes: ReusableAssetItem[];
}
```

后端 Presenter 固定同样 7 个字段；不扩展 prompt / model / seed / workflow / approval / owner /
folder / version tree / generation status / technical metadata。

## 3. 正式页面与路由（最小接线）

- API：`GET /api/projects/{project}/reusable-assets` → `ReusableAssetController@index`（B06，只读）。
- 页面：`GET /projects/{project}/reusable-assets` → `ReusableAssetController@page`（I06A 新增），
  Inertia 组件 `V2/ReusableAssets/ReusableAssetIndex`，props 仅 `projectId`。
- 两条均在 `routes/web.php` 的 `auth` 组内；本任务对 routes 仅追加：
  一行 `use ...ReusableAssetController;`、一行页面路由、一行 API 路由（B06 已有），未重排 imports、未跑 Pint。
- 未加全局大导航、未重设计工作台。

## 4. 权限与项目隔离

- 页面与 API 都经过 `auth` + `ProjectContext::assertCurrent`。
- 跨项目：页面与 API 均 **404**（`ReusableAssetPageTest::page_does_not_leak_another_project`、
  `ReusableAssetApiTest::cross_project_access_returns_404`）。
- 未登录：JSON API **401**；页面访问重定向 `/auth/login`（用 AuthenticatesUser trait 后需先 `auth()->logout()`）。

## 5. 排序（后端真源，前端不重排）

`ReusableAssetIndexService` 固定：existing 优先，再按 name 升序；前端筛选只保留原顺序，不另建排序规则。

## 6. 空 / null 处理

- 空项目：人物 / 场景均为空数组，前端 EmptyState 分别提示「当前还没有人物 / 场景母版」。
- `thumbnail_url` / `description` / `last_used_at` 为 null：卡片显示占位、「暂无说明」、「未使用」。
- `last_used_at`：后端 toJSON() ISO/JSON；前端 `formatLastUsed()` 取前 10 位显示 `YYYY-MM-DD`，
  null 显示「未使用」；不加时区配置 UI。

## 7. Prepare / View 边界（保持轻量占位）

- `prepareCharacter` / `prepareScene`：缺失项「准备建立」，正式页 toast
  「准备建立 {name} 的稳定母版（待 Work 集成）」，**不发任何写请求**。
- `viewCharacter` / `viewScene`：「查看」toast「查看人物 / 场景 {name}（详情待集成）」，**不发写请求**。
- 不做上传 / 生图 / 编辑表单 / 详情页 / CRUD API。

## 8. Mock 隔离

- 生产：`assetIndexApi`（createAssetIndexClient + http 单例）、`useReusableAssetIndex`、
  `ReusableAssetIndex.vue` / `ReusableAssetIndexBody.vue` **0 引用 DevMock**（静态断言锁死）。
- `assetIndexDevMock.ts` 只被 `ReusableAssetDevPreviewApp.vue`（DEV Preview）与前端测试引用。
- 真实后端接通后：API 失败显示 ErrorState（不 fallback Mock、不偷偷用 fixture）；Mock 因仍服务于
  独立前端回归而保留。

## 9. I06A 集成中修复的两个前端缺陷（浏览器验收发现）

1. **正式页面未挂载 ToastHost**：ToastHost 只在 AdminLayout / DevPreviewApp 挂载，Inertia 正式页
   不经过它们，导致 prepare/view toast 不显示。已在 `ReusableAssetIndex.vue` import 并挂载 `<ToastHost />`。
2. **错误态重试按钮未渲染**：共享 `ErrorState.vue` 只渲染具名插槽 `<slot name="action" />`
   （`v-if="$slots.action"`），而 C06 把「重新加载」放进默认插槽。已改为
   `<template #action>` 包裹（对齐 ImagePostEditorBody 用法）。
- 两处均加了前端静态锁测试（retry 插槽锁、ToastHost 挂载锁）。

## 10. 演示数据（仅测试 / development）

`database/seeders/ReusableAssetDemoSeeder.php`：两个项目、11 条资产、existing/missing 四态与
null 边界（含长名称「小区楼下花园」、无缩略图、null 说明 / 最近使用）。

- **不挂 DatabaseSeeder**，需显式执行：
  ```bash
  php artisan db:seed --class=ReusableAssetDemoSeeder
  ```
- 人物 / 场景名称不硬编码进生产业务逻辑；仅用于本地与浏览器验收。

## 11. 本地验证

```bash
# 后端（SQLite，.env 用 SQLite）
php artisan test

# 后端（MySQL 8.4，runtime 在 127.0.0.1:3399，库 qn_workbench_test）
$env:APP_KEY=(Select-String .env '^APP_KEY=(.+)$').Matches.Groups[1].Value
vendor/bin/phpunit -c phpunit.mysql84.xml
vendor/bin/phpunit -c phpunit.mysql84.xml --testsuite Gate --testdox

# 前端
node --experimental-strip-types --import ./tests/frontend/v2/reusableAssets/register-loader.mjs \
  --test tests/frontend/v2/reusableAssets/*.test.ts
npm run typecheck
npm run build

# 真实浏览器：php artisan serve --host=127.0.0.1 --port=8800，
# 登录 test@example.com / password，POST /api/projects/1/select 后访问
# http://127.0.0.1:8800/projects/1/reusable-assets
```

## 12. 验证结果（本任务）

- 前端 reusableAssets：31 passed；imagePost 回归 9 passed。
- `npm run typecheck`：0；`npm run build`：0。
- SQLite full：387 passed（3799 assertions）。
- MySQL 8.4：全量通过（含 Gate 并发 / 锁 / 驱动用例）。
- 真实 Laravel + Vite 浏览器 18 项：正式 URL、真实 GET、人物 / 场景 Tab、existing / missing / 全部、
  空数据、无缩略图、description null、last_used_at null、prepare toast、view toast、reload、
  跨项目 404、ErrorState + 重试恢复、353px 窄屏、console 0 error，全部通过。

## 13. 不在本任务范围

ImageBatch / AssetNameSource binding（I06B）、上传 / 生图 / 复杂 CRUD、真实 Work 调用、DAM、
合并 Foundation。PR #35 在本任务全绿后关闭并标记 superseded（不 merge）。
