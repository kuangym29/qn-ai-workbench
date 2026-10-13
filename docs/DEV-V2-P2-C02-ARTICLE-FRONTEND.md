# DEV-V2-P2-C02 公众号图文文章正式编辑器前端

- 状态：UI_IMPLEMENTED（真实后端 API 尚未合入，未联调）
- 分支：doubao/v2-article-frontend
- 基线：codex/v2-foundation @ 1fe589f8be6461ab6010b13ce5637699a3d647f8
- 执行 AI：豆包（前端 Phase 2 新页面/组件）
- 独立工作区：qn-ai-workbench-V2-P2-C02（与 C01 / C03 完全隔离）

## 1. 目标与边界

为「公众号·图文文章」提供正式编辑器前端。图文文章不是「小绿书换皮」：
不逐页、无 page_no / page_type / 逐页叙事职责 / 逐页前后承接。
正式主体围绕：标题 / 摘要 / 正文 / 配图建议 / 封面建议。
共享信息（栏目 / 来源 / 核心表达 / 整篇故事线）由 Content Item 继承，
前端只读弱展示，不要求重复录入。

本任务只新增前端文件，不修改 database / app / PHP tests / ContentBranch schema /
Foundation 页面 / C01 image_post 文件 / Production Workspace / routes/web.php /
全局导航 / 全局 layout / main。

## 2. 页面结构

正式页面：`resources/js/pages/V2/Article/ArticleEditor.vue`（Inertia，props: projectId/itemId；
路由由 Work 主程序集成时接线，本任务未改 routes/web.php）

顶部（克制）：
- 文章名称（item.title）
- 栏目 · 公众号·图文文章
- 来源 ›（column_name / column_slug）
- 共享信息弱展示（折叠 details：核心表达 / 整篇故事线）

正文区（自上而下）：
1. AI 导入（主入口）
2. 标题（单行）
3. 摘要（2 行）
4. 正文（大 textarea，长文舒适）
5. 文中配图建议（列表：位置可选 + 描述；空态 = 无配图）
6. 封面建议（2 行）
7. 制作子项轻量预留（文章内容 / 配图 / 排版 chips，非 Workflow Builder）
8. 保存（sticky footer）

未增加：负责人 / 截止时间 / 优先级 / 读者收益 / 复杂状态 / 审批层级 / SEO 字段 /
标签管理 / 复杂 CMS 元数据。

## 3. AI 导入（后端为唯一解析者）

正式链路：前端提交 raw input → 后端 preview → 前端展示 → confirm → 后端 apply。

- 前端只实现：粘贴区 / parsing 状态 / preview 状态 / error 状态 /
  added / modified / unchanged / warnings 展示组件（ArticleImportPreview.vue）/
  Confirm / Cancel。
- 不建立任何未来会与后端重复的正式解析业务逻辑。
- 开发预览使用显式标注的 DEV MOCK（`buildMockImportPreview` + 内存 apply），
  明确不进入生产路径；正式页面默认调用后端 preview / apply（后端未实现时如实报错）。

## 4. 数据合同

| 概念 | 类型 | 说明 |
| --- | --- | --- |
| 共享信息 | ArticleContentItem | title / core_expression / story_line / column_name / column_slug（继承） |
| 正式字段 | ArticleFields | headline / summary / body / image_suggestions / cover_suggestion |
| 配图建议 | ArticleImageSuggestion | position（可选数字，文中位置）+ description + note |
| 预览合同 | ArticleImportPreview | added[] / modified[] / unchanged[] / warnings[]（后端返回，前端只展示） |

API 端点（约定，待 WorkBuddy Phase 2 对齐）：
- `GET  /api/projects/{p}/v2/article/{item}` → `{"data": {item, article}}`
- `PUT  /api/projects/{p}/v2/article/{item}` → body 正式字段
- `POST /api/projects/{p}/v2/article/{item}/preview` → body `{raw}` → `{"data": ArticleImportPreview}`
- `POST /api/projects/{p}/v2/article/{item}/apply` → body `{raw}` → `{"data": workspace}`

字段命名差异（交 Work 主程序集成）：foundation `api/v2.ts` ContentItem 使用
`core_message`，本模块按任务书使用 `core_expression`；集成时归一化。

## 5. 文件清单

| 文件 | 作用 |
| --- | --- |
| resources/js/types/v2/article/article.ts | 数据合同类型 |
| resources/js/composables/v2/article/articleCore.ts | 纯函数：归一化 / 配图列表 / DEV MOCK preview |
| resources/js/composables/v2/article/articleApi.ts | API adapter（约定端点） |
| resources/js/composables/v2/article/useArticleEditor.ts | 编辑器状态机（后端 preview/apply 链路） |
| resources/js/components/V2/Article/ArticleEditorBody.vue | 编辑器主体（纯展示，可独立预览） |
| resources/js/components/V2/Article/ArticleAiImport.vue | AI 导入入口（无解析逻辑） |
| resources/js/components/V2/Article/ArticleImportPreview.vue | 变更预览（消费后端合同） |
| resources/js/components/V2/Article/ArticleImageSuggestions.vue | 配图建议编辑（无配图/位置可选） |
| resources/js/components/V2/Article/ArticleDevPreviewApp.vue | DEV 预览 App（示例 + MOCK providers） |
| resources/js/pages/V2/Article/ArticleEditor.vue | 正式 Inertia 页面 |
| resources/js/v2-article-preview.ts + v2-article-preview.html | 无 Laravel 独立预览入口 |
| tests/frontend/v2/article/articleCore.test.ts | 核心纯函数测试（node:test） |
| docs/DEV-V2-P2-C02-ARTICLE-FRONTEND.md | 本文档 |

## 6. 配图处理原则

- 无配图：`image_suggestions = null`，UI 空态明确显示「本文章不配图」。
- 封面建议：主区域独立字段。
- 文中某位置配图建议：position 可选数字 + 描述。
- 本任务不开发 MediaBatch / 批量生图流程；需要时后续进入图片制作。

## 7. 验证

- `npm run typecheck`：PASS（vue-tsc --noEmit）
- `npm run build`：PASS（vite build）
- `node --experimental-strip-types --test tests/frontend/v2/article/articleCore.test.ts`：PASS
- 浏览器（Vite dev + 独立预览入口 /v2-article-preview.html）：
  - 顶部共享信息弱展示 ✓
  - AI 导入 → Mock preview → 确认 / 取消 ✓
  - 标题 / 摘要 / 正文（长正文舒适）✓
  - 配图建议增删改 / 无配图空态 ✓
  - 封面建议 ✓
  - 错误状态（空粘贴）✓
  - 保存（DEV 内存）✓

## 8. 未完成 / 遗留

- REAL_API_INTEGRATED：未达成（WorkBuddy Phase 2 后端未合入）。
- 正式路由接线由 Work 主程序集成时完成（本任务未改 routes/web.php）。
- 正式 preview / apply 由后端提供；当前 DEV 预览使用显式 MOCK。
