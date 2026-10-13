# DEV-V2-P3-C03 微信视频号视频制作正式前端

- 状态：UI_IMPLEMENTED（真实后端 API / 视频生成尚未合入，未联调）
- 分支：doubao/v2-video-frontend
- 基线：codex/v2-foundation @ 1fe589f8be6461ab6010b13ce5637699a3d647f8
- 执行 AI：豆包（前端 Phase 3 新页面/组件）
- 独立工作区：qn-ai-workbench-V2-P3-C03（与 C01 / C02 完全隔离）

## 1. 目标与边界

为微信视频号「视频制作」提供正式编辑器前端。视频页面不复制图片文章
已存在的信息；只回答三个问题：
1. 整条视频整体怎么做？（batch_instruction，一段完整文本）
2. 旁白说什么？（narration，一份完整正式旁白）
3. 每张图具体怎么动、动多久、有什么特殊注意？（units）

本任务只实现 UI，不开发真实视频生成（按钮 emit / toast 演示）。
不修改 database / app / PHP tests / ContentBranch schema / Foundation 页面 /
C01 image_post / C02 article / routes/web.php / 全局导航 / 全局 layout / main。

## 2. 页面结构

正式页面：`resources/js/pages/V2/Video/VideoEditor.vue`（Inertia，props: projectId/itemId；
路由由 Work 主程序集成时接线，本任务未改 routes/web.php）

顶部（克制，只显示）：
- 《内容名称》（video.title，不做成「视频名称」新字段）
- 来源 ›（source_label；未来点击可查看栏目 / 来源选题 / 来源内容，默认不展开）

三个核心块：
1. 整批视频制作说明 —— 一段完整文本（整组怎么动 / 节奏 / 整体动态 / 转场倾向 / 人物动作原则）
2. 完整旁白稿 —— 一份完整正式旁白（前端不要求逐片段手工拆分，系统未来自动分配）
3. 视频片段 —— 每个来源图片 / 页面对应一个单元：
   - 用户编辑：动态说明 / 时长（秒）/ 备注（可选）
   - 系统/继承弱展示：序号（01/02…）、来源页标签（封面 / P2…）

底部：预计总时长（自动计算）+ [复制整批视频任务] [先做试样] [选择片段生成] [保存]
（前三者仅 UI / emit，不开发真实视频生成）。

## 3. 数据合同

| 概念 | 类型 | 说明 |
| --- | --- | --- |
| 视频制作 | VideoProduction | title / source_label / batch_instruction / narration / units[] / source_changed / source_change_summary |
| 视频单元 | VideoUnit | id / order / source_page_id / source_label / dynamic_instruction / duration_seconds / note |
| 系统计算 | VideoEditorWorkspace | video + total_duration + timeline（只读） |
| 保存输入 | VideoSaveInput | 仅用户可编辑字段（batch_instruction / narration / units.id + dynamic_instruction + duration_seconds + note） |

API 端点（约定，待 WorkBuddy Phase 2 对齐）：
- `GET /api/projects/{p}/v2/video/{item}` → `{"data": workspace}`
- `PUT /api/projects/{p}/v2/video/{item}` → body VideoSaveInput

## 4. 关键 UI 行为

- 时间轴：系统自动能力。日常页面不展示；点击「查看时间轴」以只读方式展开
  （片段 / 起止时间 / 旁白对应关系），不做专业剪辑软件式 timeline。
- 来源内容变化提醒：⚠ + [查看修改] [继续沿用]，不显示 Content Revision V3 /
  Video Based On V2 等技术术语。
- 视频号单独选择（方案 A）：不额外出现「基础图片批次」渠道；
  视频制作内部未来包含「准备/生成基础图片 → 视频化」，本 UI 不增加额外一级流程。

## 5. 文件清单

| 文件 | 作用 |
| --- | --- |
| resources/js/types/v2/video/video.ts | 数据合同类型 |
| resources/js/composables/v2/video/videoCore.ts | 纯函数：总时长 / 时间轴 / 排序 / 保存收敛 |
| resources/js/composables/v2/video/videoApi.ts | API adapter（约定端点） |
| resources/js/composables/v2/video/useVideoEditor.ts | 编辑器状态机 |
| resources/js/components/V2/Video/VideoEditorBody.vue | 编辑器主体（三核心块 + 底部 + 提醒） |
| resources/js/components/V2/Video/VideoUnits.vue | 视频单元列表 |
| resources/js/components/V2/Video/VideoUnitRow.vue | 单元单行（动态 / 时长 / 备注） |
| resources/js/components/V2/Video/VideoTimeline.vue | 只读时间轴 |
| resources/js/components/V2/Video/VideoDevPreviewApp.vue | DEV 预览 App（示例 + 内存保存） |
| resources/js/pages/V2/Video/VideoEditor.vue | 正式 Inertia 页面 |
| resources/js/v2-video-preview.ts + v2-video-preview.html | 无 Laravel 独立预览入口 |
| tests/frontend/v2/video/videoCore.test.ts | 核心纯函数测试（node:test） |
| docs/DEV-V2-P3-C03-VIDEO-FRONTEND.md | 本文档 |

## 6. 验证

- `npm run typecheck`：PASS
- `npm run build`：PASS
- `node --experimental-strip-types --test tests/frontend/v2/video/videoCore.test.ts`：PASS
- 浏览器（Vite dev + 独立预览入口 /v2-video-preview.html）：
  整批说明 / 完整旁白 / 6 个视频单元 / 动态·时长·备注编辑 / 自动总时长 /
  查看时间轴（只读）/ 来源修改提醒 / 长旁白 / 窄窗口基础可用性

## 7. 未完成 / 遗留

- REAL_API_INTEGRATED：未达成（WorkBuddy Phase 2 后端未合入）。
- 正式路由接线由 Work 主程序集成时完成（本任务未改 routes/web.php）。
- 复制任务 / 先做试样 / 选择片段生成：仅 UI / emit，真实视频生成后续开发。
