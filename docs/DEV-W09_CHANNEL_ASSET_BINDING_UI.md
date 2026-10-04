# DEV-W09｜Channel Asset Binding UI

让每个 ChannelTask 明确记录「每一页最终选用了哪一个共享 AssetVersion」。**渠道不复制图片**——绑定只是指向共享资产的一个引用。

**本轮 Migration 数量为 0**：W09 只做前端与集成验收，未新增或修改任何 migration。渠道素材绑定相关的 migration 属 DEV-011A，已在 W09 之前的基线中合入，不计入本轮。

按当前 `main` 的实际 diff 核对（本轮三个提交 `e46eb4f` / `14d4dc1` / `4cd9ec1`）：

- `app/**`、`database/**`、`routes/**`：**未改动**；
- `tests/Feature/**`：**有改动**——集成验收阶段由 `14d4dc1` 新增 `tests/Feature/W09IntegrationVerificationTest.php`（详见下文「运行验收」），该文件随后在 `93bb355`（DEV-W11 Auth Lite）中被调整；
- 前端实现为 5 个文件：`resources/js/api/channelAssets.ts`、`resources/js/api/types.ts`、`components/ChannelAssetBindingDialog.vue`、`components/ChannelAssetBindings.vue`、`pages/Production/Workspace.vue`（其中 `types.ts` 后续由 DEV-W10 追加了查重相关字段，未改动 W09 的绑定字段）。

三份中央共享文档留给 ChatGPT 统一同步。

## 角色映射

哪个角色可绑定完全由**服务端根据 Channel 推导**，用户不能自由选择：

| Channel | 期望角色 | 中文 |
| --- | --- | --- |
| `wechat_official` | `copy_master` | 有文案定稿图 |
| `wechat_channels` | `clean_master` | 无文案底图 |

客户端的 `expectedRoleForChannel()` 只是标签镜像，服务端始终是权威。

## API 契约

作用域为 `Project → Column → Topic → ContentItem → ProductionTask → ChannelTask`：

| 方法 | 路径 | 返回 |
| --- | --- | --- |
| GET | `/production/channels/{channel}/assets` | `ChannelAssetWorkspace` |
| POST | `/production/channels/{channel}/assets/bindings` | `ChannelAssetBinding` |

POST body **只有** `content_page_id` 与 `asset_version_id`。`role`、`asset_id`、`copy_revision_id`、`binding_no` 以及全部归属键都由服务端推导——客户端不构造 `binding_no`，不手工拼 `current_binding`，一切以后端返回为准。

## UI 位置

不做独立全局页面，也不做「素材中心」。绑定区块**内嵌在已有的渠道 Card 内**（`共享视觉资产` 区块之下、渠道任务之内），两个渠道各自一份。

### Channel Card 摘要

显示素材来源中文名、角色字面量（`copy_master` / `clean_master`），以及 `已绑定 X / Y 页`。完整时显示 active 徽章「全部页面已绑定」，不完整时显示「还有 N 页未绑定」。

### 页面矩阵

按后端 `pages` 顺序渲染，显示「第 X 页 · 页面类型中文名」。每页展示：

- **当前绑定**（`current_binding`）：`Revision N`、Asset `vN`、`original_name`、`storage_disk : storage_path`；为空时显示「当前 Revision 尚未绑定」。
- **可选版本**（`available_versions`）：由后端按「当前 ProductionTask.copy_revision_id + 该渠道 expected_asset_role」预先收窄，**UI 原样渲染、不再自行过滤**。用户选中一个版本即可绑定，无需输入 Asset ID、Role、Revision 或 Binding No。
- **无可选版本**时提示「当前 Revision 尚没有可用于本渠道的共享视觉资产」，并按渠道给出具体指引（公众号提示先登记 `copy_master`，视频号提示先登记 `clean_master`），**不会自动创建 AssetVersion**。

## current 与 latest binding

与 DEV-W08 的 current/latest 资产版本原则一致：

- `current_binding`：**只看** ProductionTask 当前 pinned Revision，是「正在使用」的那一条；
- `latest_binding`：该 Channel + Page 全部历史绑定中最新的记录，**可能属于更早的 Revision**。

当 `current_binding` 为空而 `latest_binding` 非空时，主位显示「当前 Revision 尚未绑定」，下方另起一行显示「历史最新：Revision N · Binding #N」。**历史绑定绝不会被自动当作当前绑定**。

## 完整度与终态门禁镜像

`is_complete` 表示当前 Revision 下所有页面都已绑定。前端同步镜像 DEV-011A 的服务端门禁，未知状态（未加载 / 加载失败）一律按**未完整**处理，绝不在状态不明时放开终态动作：

| 动作 | 追加条件 |
| --- | --- |
| 公众号 `scheduled` / `published` | artwork approved + production current + **is_complete** |
| 视频号 `video_status → approved` | production current + **is_complete** |
| 视频号 `scheduled` / `published` | 视频已 approved + **is_complete** |

不完整时按钮禁用并显示渠道对应的说明：「请先完成全部页面的公众号素材绑定。」/「请先完成全部页面的视频号素材绑定。」

**中间视频状态不要求完整**：`not_started` / `in_progress` / `pending_review` 不引入新的门禁，只有 `approved` 这一终态需要完整绑定。已 `published` 的渠道保持终态，不因绑定读取异常而增加任何回退操作，也不修改历史发布状态。服务端永远是最终 Gate。

## stale Production

当 `is_production_copy_current = false` 时，**仍然允许查看与重新绑定**——后端正式规则允许向 pinned Revision 追加绑定。界面明确提示「当前渠道任务仍绑定旧正式文案 Revision N；本次素材绑定也只属于该旧 Revision。」不擅自禁用绑定，但既有的发布 / 视频终态门禁仍以后端为准。

## restart 行为

`restart-with-current-copy` 会改变 `copy_revision_id`，因此绑定区块挂在既有 `reload()` 末尾统一刷新：Production、ChannelTasks、共享资产、**以及两个渠道的绑定工作区**都会重新拉取。历史绑定依旧存在（不清除），新 Revision 的 `current_binding` 自然由服务端重新给出。

## 错误处理

沿用 DEV-W06.1 的分工：

- **加载失败** → 只在该渠道的区块内显示错误文案，不影响制作与其它区块；
- **写操作 422** → 只 Toast，**绑定弹窗保持打开**、用户已选中的版本保留，不切 ErrorState；
- **作用域 404** → Toast 后 `router.visit('/projects')`，绝不根据 URL 自动 `selectProject`。

绑定成功后：Toast「已绑定渠道素材」→ 关闭弹窗 → 重新 GET 该渠道的绑定工作区。弹窗**绝不在 POST 之前关闭**，否则 422 会丢失用户选择。

## 不做的事

- 不复制 AssetVersion，不生成渠道专属图片；
- 不做文件上传、拖拽或图片预览；
- 不做 Channel-specific artwork；
- 不提供 Binding 的删除、编辑或回滚——绑定只是引用；
- 不调用真实微信 API；
- 不新增 Migration，不修改后端。

---

## 运行验收（DEV-W09-INTEGRATION，2026-10-03）

基线：`origin/main` = `e2d3d8d`（DEV-011A.1 已合入）。本分支由 `c73bcf3` rebase 到该 main，零冲突，rebase 前后本任务六个文件内容字节一致。

### 自动验收

| 项目 | 结果 |
| --- | --- |
| `npm run typecheck` | 零错误 |
| `npm run build` | 成功，830 modules |
| ChannelAssetBinding 专项 | 7 passed / 152 assertions |
| ChannelTask 专项 | 38 passed / 477 assertions |
| W09 集成验收 | 9 passed / 125 assertions / 45 条编号项全 PASS |
| 全量 PHP | 210 passed / 2195 assertions |
| `vendor/bin/pint --test` | 166 files PASS |
| `git status` | 干净 |

集成验收脚本见 `tests/Feature/W09IntegrationVerificationTest.php`，全部走真实 HTTP 内核、真实冻结路由、真实 Controller 与真实数据库，并把解码后的响应与本目录文档承诺的 TS 字段逐项比对（Workspace 11 / Page 7 / Binding 13）。

### 服务端已确认的边界语义

写验收脚本时逐条实测确认，UI 逻辑必须与之一致：

1. `is_production_copy_current` 为「pinned revision 是否等于当前最新正式 revision」。出现新正式 Revision 即 stale，此时旧 Revision 的版本**仍可绑定**，且绑定归属旧 Revision（响应带旧 `copy_revision_no`）。
2. **404 与 422 的分界**：`findOrFail` 类（不存在的 `asset_version_id`、跨篇目页面、跨项目 scope、未创建渠道、未定义的路由成员）返回 404，不泄露存在性；业务校验类（Revision 不匹配、角色不匹配、页面不属于 pinned Revision、伪造字段、缺字段）返回 422。
3. `total_page_count === 0` 时 `is_complete` 为 false——空 Production 不得通过发布门禁。
4. 绑定为 append-only：同一页重复绑定生成新的 `binding_no`，`current_binding` 前移，历史行不被就地修改。
5. 页面矩阵只由 pinned Revision 的页面快照构成；`asset_id` 表示该页已登记的共享 Asset，与是否已绑定无关（已登记未绑定时 `asset_id` 有值而 `current_binding` 为 null）。
6. Revision restart 后完整度重置，旧绑定降级为 `latest_binding` 历史，**永不自动提升为当前**。
7. `restart-with-current-copy` 不接受任何 payload。

### 刷新恢复

`Production/Workspace.vue` 不使用 localStorage / sessionStorage，界面完全由 `onMounted → load → reload → loadAllChannelAssets` 从服务端重建。因此「刷新后状态恢复」等价于「GET 是绑定表的完整无状态投影」：连续两次 GET 响应完全相同，且 GET 自带渲染所需全部数据（含 `asset_version.file` 定位信息），无需二次请求。
