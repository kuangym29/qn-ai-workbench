# V2-P3-I06B — Reusable Asset ↔ ImageBatch Binding · 交付固定报告

**状态：V2-P3-I06B_DONE** ✅
**结论：全部正式交付门通过。Foundation 未 merge。未进入 Stage2B。**

---

## 0. 执行原则（本次重申）

- 不重新开发既有功能，不重做已有测试。
- GitHub 邮箱验证问题此前已处理，本次推送畅顺（首推即成功，无 502 重试）。
- 完成后即停止；不进入 Stage2B；不 merge Foundation。

---

## 1. 七道正式交付门 — 结果

| # | 门 | 结果 | 证据 |
|---|----|------|------|
| 1 | push 分支 `workbuddy/v2-p3-i06b-reusable-asset-binding` | ✅ | 远端 HEAD = `bc3c0cb` |
| 2 | 建 Draft PR → `codex/v2-foundation` | ✅ | PR **#43**（open / draft） |
| 3 | 远端 HEAD == 本地最终 HEAD | ✅ | 远端 `bc3c0cb` == 本地 `bc3c0cb` |
| 4 | 最终 CI：`php-tests` + `mysql84-gate` | ✅ | Run **38549290353** = success（两 job 均 success） |
| 5 | 真实 Laravel + Vite 浏览器验收 | ✅ | **46/46** 浏览器检查通过 |
| 6 | Fresh Review | ✅ | 见 §3 |
| 7 | 关 PR #41（未 merge / 标记 superseded）；PR #40 保持 open/draft/frozen | ✅ | PR #41 closed+unmerged；PR #40 open/draft |

---

## 2. 最终 CI（门 4）

- 触发：push `bc3c0cb` 后自动触发 `V2 Foundation CI`（针对 PR #43）。
- Run ID：`38549290353`，结论 `success`。
- Jobs：
  - `php-tests` → **success**
  - `mysql84-gate` → **success**
- 历史对照：
  - 修复前 `e3631b3d` 的 run `38542885590` 曾 failure（MySQL JSON 列键序规范化导致 `assertSame` 失败）。
  - 修复后 `300ac67d` 的 run `38544610019` = success。
  - 本次最终 HEAD `bc3c0cb`（仅追加浏览器验收产物 + .gitignore）run `38549290353` = success。

### CI 修复说明（仅测试健壮性，生产代码零改动）
- 文件：`tests/Feature/V2/ImageBatchReusableAssetBindingTest.php`
- 现象：MySQL 8.4 的 JSON 列在回读时会把对象键规范化为字母序（`name,type,status`），而 SQLite/PHP 保留插入序（`type,name,status`）。`assertSame` 对字典键序敏感 → MySQL 下失败、SQLite 下通过。
- 处理：仅把「从 MySQL JSON 列回读的 run 快照 `assets`」断言由 `assertSame` 改为 `assertEquals`（放松字典键序、保留列表顺序，与 JS 按键名读取的语义一致）。GET 接口的 `assets` 仍用 `assertSame`（PHP 运行时生成，两引擎顺序一致）。
- 这是测试对真实 MySQL 行为的正确适配，非绕过。

---

## 3. Fresh Review（门 6）

范围：I06B 绑定核心 + 测试修复 + 浏览器验收产物。

- **绑定核心 `app/Services/V2/ImageBatchAssetResolver.php`**：从 `AssetNameSource` 读取每页引用 → 按 `type|name` 整批聚合去重（保持出现顺序）→ 同名状态冲突按权重 `new_in_batch(3) > missing(2) > existing(1)` 取最强 → 输出 `list<{type,name,status}>`。逻辑清晰、无副作用、与验收断言一致。
- **测试修复**：见 §2，定向且带注释，生产代码未动。
- **浏览器验收产物**（新增，不进 CI 执行，仅本地人工验收）：
  - `database/seeders/I06bBrowserSeeder.php`（登录用户 + 项目 + 4 个复用资产 + 图文分支 3 页；未接入 `DatabaseSeeder`，按需显式 seed）。
  - `tests/frontend/v2/i06b/server-router.php`（本地 `php -S` 路由，`-t public` 以正确解析 `/build` 静态资源）。
  - `tests/frontend/v2/i06b/browserAcceptance.mjs`（Playwright 真实浏览器验收脚本）。
- **清理**：删除 4 个临时 debug 脚本；`.gitignore` 追加本地验收用 sqlite（`i06b-browser.sqlite` 等），不入库。

---

## 4. 真实浏览器验收（门 5）— 46/46 通过

环境：真实 Laravel（`php -S` + SQLite）+ 真实 Vite 构建产物（`public/build`），Playwright + 本地 Chromium。

覆盖与结果：

- **A. Reusable Asset 正式页面**（人物 / 场景复用索引）
  - A1 标题 `人物 / 场景复用索引` ✅
  - A2 Tab 计数 人物(2)/场景(2) ✅
  - A3/A6 默认/切换 Tab 渲染卡片数正确 ✅
  - A4/A5/A7/A8 卡片状态 `已有`/`缺失` 正确（妈妈/爸爸/客厅/厨房）✅
  - A9/A10 筛选 `缺失`/`已有` 各 1 张 ✅
- **B. ImageBatch 正式页面**
  - B1 进入页面 ✅；B2 GET workspace 渲染 3 单元 ✅
  - B3 服务端内容标题渲染 ✅；B4 默认空语义（assets 空状态）✅
- **C. 无横向滚动**：Reusable Asset 与 ImageBatch 两页 @353px / @390px 均 `scrollWidth == clientWidth` ✅
- **D. Mobile Drawer**（仅 ImageBatch 页，因其挂 `AdminLayout`；ReusableAssetIndex 按 I06A 既有设计不挂 AdminLayout）
  - 汉堡按钮 `切换导航菜单` @353/390 可见 ✅
  - 抽屉打开显示背景遮罩 ✅
  - 抽屉打开/关闭后均无横向滚动 ✅
- **E. 控制台 / 网络**
  - E1 console 0 意外错误 ✅；E2 无意外 5xx ✅
- **F. ImageBatch 全流程**
  - F1 dirty 标记 ✅；F2 整批备注 ✅；F3 单元轻量修正 ✅
  - F4 history 基线 ✅；F5 Draft Save(PUT) → 整批生成 → history +1 ✅
  - F6 history 显示 queued + 全覆盖（P1、P2、P3）✅
  - F7 先做试样（选定页）✅；F8 选择部分页面生成 ✅
  - F9 复制整批生图任务（服务端任务文案，含说明/备注/单元）✅；F10 剪贴板回读 ✅
  - F11a 真实 API 改来源页（200）✅；F11 真实来源变更检测（1 条警告）✅
  - F12 继续沿用 → 清除警告 ✅；F13 沿用保留生产状态（待制作）✅
  - F14 重新生成 → 状态转 `需修改` ✅

> 说明：验收环境无队列 worker，run 提交后为 `queued` 状态（符合设计，不影响 UI 流程与历史聚合）。全部 UI 行为、客户端逻辑、服务端接口均按预期工作。

---

## 5. PR 处置（门 7）

- **PR #43**（本交付）：`workbuddy/v2-p3-i06b-reusable-asset-binding` → `codex/v2-foundation`，**open / draft**。作为 I06B 唯一规范交付入口。
- **PR #41**（I06A integration，head `doubao/v2-reusable-asset-index-integration`）：**已关闭、未 merge**，已加评论标记为被 I06B（PR #43）superseded——I06B 已完整包含 I06A（B06 后端 + C06 前端）并追加 I06B 绑定。避免重复集成。
- **PR #40**（P02A，head `work/v2-p3-promotion-stage2a`）：**保持 open / draft / frozen**，未改动。
- **Foundation 未 merge。**

---

## 6. 最终 HEAD / 交付物

- 分支：`workbuddy/v2-p3-i06b-reusable-asset-binding`
- 本地 == 远端 HEAD：`bc3c0cb996ca8f60e1100507a82d00bcdda8aae9`
- 关键提交：
  - `300ac67d` 测试修复（assertSame→assertEquals，MySQL JSON 键序适配）
  - `bc3c0cb` 追加浏览器验收产物（seeder/router/harness）+ .gitignore

---

## 7. 收尾动作

- 本地验收用 dev server（端口 8077）可保留或关闭；数据库 `i06b-browser.sqlite` 已 gitignore，不入库。
- 不再进入 Stage2B；不 merge Foundation。
- **标记：V2-P3-I06B_DONE。**
