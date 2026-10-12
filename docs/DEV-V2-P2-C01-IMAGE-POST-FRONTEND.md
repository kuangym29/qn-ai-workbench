# DEV-V2-P2-C01 公众号图片文章（小绿书）内容编辑器 + AI 导入预览前端

- 状态：UI_IMPLEMENTED（真实后端 API 尚未合入，未联调）
- 分支：doubao/v2-image-post-frontend
- 基线：codex/v2-foundation @ 1fe589f8be6461ab6010b13ce5637699a3d647f8
- 执行 AI：豆包（前端 Phase 2 新页面/组件）

## 1. 目标与边界

为「公众号·图片文章（小绿书）」提供内容编辑器 UI，AI 粘贴为主要录入方式。
本任务只做前端页面/组件/类型/纯函数，不开发后端模型、migration、service、
controller、backend tests；不修改 Foundation 数据结构、Production Workspace、
图片生成、视频制作、公众号图文文章。

正式导航接线由 Work 主程序集成时完成；本任务仅提供独立开发预览入口。

## 2. 页面结构

正式页面：`resources/js/pages/V2/ImagePostEditor.vue`（Inertia，props: projectId/itemId）

顶部（克制，不额外堆字段）：
- 文章名称（item.title）
- 栏目 · 公众号·图片文章
- 来源 ›（column_name / column_slug）
- 核心表达（core_expression）
- 整篇故事线（story_line）

正文区：
- AI 导入（主入口，置顶）
- 页面卡列表（每页 8 项信息，卡片化，可快速浏览）
- 保存整篇（底部 sticky）

## 3. 每页内容卡（固定 8 项）

| 项 | 字段 | UI 处理 |
| --- | --- | --- |
| 页码 | page_no | 卡片头部圆形徽标 |
| 页面类型 | page_type | Badge（封面/正文/栏目收尾/固定封底） |
| 叙事职责 | narrative_role | 常用信息区（编辑模式 2 行） |
| 本页发生的新事实 | new_fact | 常用信息区 |
| 最终文字 | final_copy | 常用信息区优先（3 行） |
| 画面证据 | visual_evidence | 常用信息区优先（3 行） |
| 前后页承接 | continuity | 常用信息区 |
| 制作备注 | production_note | 弱化区：小字、默认折叠 |

- 展示模式：按 IMAGE_POST_FIELD_ORDER 过滤有内容的字段；备注单独弱化。
- 编辑模式：6 个业务字段 textarea；保存本页 emit `update`。
- fixed_back_cover：明确「固定内容 · 只读」，禁止编辑/移除；展示固定模板提示。

## 4. AI 导入流程

主流程（`composables/v2/useImagePostEditor.ts` 状态机）：

```
[粘贴 AI 内容] → 解析（parseAiPaste） → loading/error
               → 变更预览（buildImportPreview） → 确认导入 / 取消
```

- 视觉表达：新增（绿）/ 修改（琥珀，before→after 对照）/ 未变化（灰，聚合为
  `未变化 P1 / P2 / P5`）。
- 确认前绝不覆盖现有页面：确认按钮只在有变更时可用，preview 头部明示
  「确认前不会覆盖任何页面」。
- 局部更新：只粘贴某一页内容时，unchanged 列出所有未被触碰的现有页，
  预览清楚传达「只影响第 N 页」；无需重新导入整篇。
- 确认导入只合入内存 pages；持久化由「保存整篇」全量提交，避免多次小请求。

## 5. 文件清单

| 文件 | 作用 |
| --- | --- |
| resources/js/types/v2/imagePost.ts | 数据合同类型（任务书字段命名） |
| resources/js/composables/v2/imagePostCore.ts | 纯函数：解析 + Diff 计算（无 Vue 依赖） |
| resources/js/composables/v2/imagePostApi.ts | API adapter（约定端点） |
| resources/js/composables/v2/useImagePostEditor.ts | 编辑器状态机 composable |
| resources/js/components/V2/ImagePostEditorBody.vue | 编辑器主体（纯展示，可独立预览） |
| resources/js/components/V2/ImagePostPageCard.vue | 页面卡 |
| resources/js/components/V2/ImagePostDiffPreview.vue | 变更预览 |
| resources/js/components/V2/ImagePostAiImport.vue | AI 导入入口 |
| resources/js/components/V2/ImagePostDevPreviewApp.vue | DEV 预览 App（固定示例数据） |
| resources/js/pages/V2/ImagePostEditor.vue | 正式 Inertia 页面 |
| resources/js/pages/V2/ImagePostDevPreview.vue | DEV 预览 Inertia 页面（DEV ONLY） |
| resources/js/v2-image-post-preview.ts + v2-image-post-preview.html | 无 Laravel 独立预览入口 |
| tests/frontend/v2/imagePostCore.test.ts | 核心纯函数测试（node:test） |
| docs/DEV-V2-P2-C01-IMAGE-POST-FRONTEND.md | 本文档 |
| routes/web.php | 仅追加 2 条 DEV 入口路由（见 §8） |

## 6. AI 粘贴解析格式（纯前端解析器）

`parseAiPaste` 支持两种形态，无法识别的内容进 warnings，不静默丢弃：

1. JSON 数组：`[{ "page_no": 1, "page_type": "cover", "final_copy": "…" }, …]`
2. 宽松标记文本：
   - 页码标记：`第N页` / `P1` / `Page N` / `[N]` / `N.`
   - 字段行：`叙事职责：…` / `最终文字：…` / `画面证据：…` / `制作备注：…` 等
   - 无页码标记：整段一页并按出现顺序分配页码，追加警告
   - 页面类型行：`页面类型：封面` / `类型：fixed_back_cover` 等

## 7. 数据合同与映射差异（交 Work 主程序集成）

任务书字段 → 前端类型：

| 任务书 | 类型定义 | 备注 |
| --- | --- | --- |
| title | `ImagePostContentItem.title` | 与 foundation `api/v2.ts` 一致 |
| core_expression | `core_expression` | **差异**：foundation `ContentItem` 实际字段为 `core_message`；本模块按任务书命名，集成时由 adapter/主程序归一化 |
| story_line | `story_line` | 与 foundation 一致 |
| page_no / page_type / narrative_role / new_fact / final_copy / visual_evidence / continuity / production_note | `ImagePostPage` 全字段 | foundation 无对应类型，本轮新建 |

API 端点（约定，待 WorkBuddy Phase 2 对齐）：
- `GET /api/projects/{project}/v2/image-post/{item}` → `{"data": {item, pages}}`
- `PUT /api/projects/{project}/v2/image-post/{item}` → body `{core_expression?, story_line?, pages[]}`

真实端点路径如有不同，只需替换 `composables/v2/imagePostApi.ts` 的 base 函数。

## 8. 开发入口与路由

任务书允许本任务自带开发入口；正式导航接线由 Work 主程序完成。

`routes/web.php` 仅追加（不触碰其它路由）：

```php
Route::middleware('auth')->group(function (): void {
    Route::get('/projects/{project}/v2/image-post-preview', fn (int $project) => Inertia::render('V2/ImagePostDevPreview', ['projectId' => $project]));
    Route::get('/projects/{project}/v2/image-post/{item}', fn (int $project, int $item) => Inertia::render('V2/ImagePostEditor', ['projectId' => $project, 'itemId' => $item]));
});
```

无 Laravel 环境预览：`npm run dev` 后访问 `/v2-image-post-preview.html`
（独立挂载 ImagePostEditorBody + 固定示例数据，不请求 /api）。

## 9. 验证

- `npm run typecheck`：PASS（vue-tsc --noEmit，0 error）
- `npm run build`：PASS（vite build，878 modules，无 warning）
- `node --experimental-strip-types --test tests/frontend/v2/imagePostCore.test.ts`：10/10 PASS
- 浏览器（Vite dev + 独立预览入口）：
  - 页面卡可读性 ✓（封面/正文/栏目收尾/固定封底）
  - AI 导入粘贴 → 解析 → 变更预览 ✓
  - 新增/修改/未变化视觉表达 ✓
  - 确认导入 → 合入内存 + 未保存提示 ✓
  - 取消 → 恢复 idle ✓
  - 编辑模式进入/保存本页按钮 ✓
  - fixed_back_cover 只读保护 ✓（无编辑/移除）
  - 局部更新（只贴 P4 → 仅 P4 变更 + 未变化 P1/P2/P3）✓
  - 长文字 / 多页面状态 ✓

## 10. 未完成 / 遗留

- REAL_API_INTEGRATED：未达成（WorkBuddy Phase 2 后端未合入，未联调真实 API）；
  本次为 UI_IMPLEMENTED。
- 真实端点的字段命名/路径差异由 Work 主程序集成时对齐（见 §7）。
- routes/web.php 的 2 条 DEV 路由：正式导航接线时可按需移除或替换。
