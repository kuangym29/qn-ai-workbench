# DEV-W09｜Channel Asset Binding UI　验收清单与交付说明

| 项 | 内容 |
|---|---|
| 任务编号 | DEV-W09 / DEV-W09-INTEGRATION |
| 任务名称 | Production Workspace 渠道素材绑定 UI |
| 仓库 | kuangym29/qn-ai-workbench |
| 交付分支 | `workbuddy/DEV-W09-channel-asset-ui` |
| 集成前 main | `e2d3d8dff51579fb7bfa6604e0805e906499afe8` |
| 集成后 main | `4cd9ec15df1a31925f9dd93557e9905938b31f99` |
| 集成方式 | fast-forward（保留 W09 的 3 个提交，未产生 merge commit） |
| 验收结论 | **通过** |

---

## 一、交付物清单

### 交付文件（恰好 7 个，5 新增 + 2 修改）

| # | 状态 | 文件路径 | 说明 |
|---|---|---|---|
| 1 | 新增 | `resources/js/api/types.ts` 变更部分 | `ChannelAssetBinding` / `ChannelAssetPageBinding` / `ChannelAssetWorkspace` / `ChannelAssetBindingInput` 类型与 `expectedRoleForChannel()` |
| 2 | 新增 | `resources/js/api/channelAssets.ts` | 两个冻结 endpoint 的 adapter：`workspace` (GET) / `bind` (POST) |
| 3 | 新增 | `resources/js/components/ChannelAssetBindings.vue` | 渠道卡片内嵌的绑定区块（页面矩阵、完整度 Badge、当前/历史绑定） |
| 4 | 新增 | `resources/js/components/ChannelAssetBindingDialog.vue` | 版本单选弹窗（纯定位信息，无上传、无预览） |
| 5 | 修改 | `resources/js/pages/Production/Workspace.vue` | 接入加载/绑定逻辑、完整度门禁镜像、两个 Channel Card 内嵌绑定区块 |
| 6 | 新增 | `tests/Feature/W09IntegrationVerificationTest.php` | 前后端集成运行验收（9 passed / 125 assertions / 45 条编号项） |
| 7 | 新增 | `docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` | 任务专属文档（含运行验收章节） |

### 范围声明

- **Migration 数量：0**，未改动 `app/**`、`database/**`、`routes/**`
- **DEV-011A 后端：零改动**
- **D09 / DuplicateCheck：零改动**
- **DEV-D10.CLEAN：未进入 W09 分支或本次集成**
- **Auth Lite：零改动**
- 无无关依赖变更，无无关格式化变更

---

## 二、验收标准与验收结果

### 2.1 前后端契约

| 验收项 | 标准 | 结果 |
|---|---|---|
| 路由一致性 | 前端 adapter 与 `routes/web.php` 冻结 endpoint 逐字一致 | ✅ |
| Workspace 字段 | 后端响应含前端声明的全部 11 个字段 | ✅ |
| Page 字段 | 后端响应含前端声明的全部 7 个字段 | ✅ |
| Binding 字段 | 后端响应含前端声明的全部 13 个字段 | ✅ |
| 角色归属 | 资产角色由服务端根据 Channel 推导，前端不可选 | ✅ |
| binding_no | 由服务端生成，前端不提交 | ✅ |
| 修改/删除入口 | 前端未新增 Binding 的修改或删除入口 | ✅ |

### 2.2 公众号（wechat_official / copy_master）

| 验收项 | 结果 |
|---|---|
| `expected_asset_role` = `copy_master`（服务端权威） | ✅ |
| 页面矩阵取自固定（pinned）Revision 的页面快照 | ✅ |
| `current_binding`（当前 Revision 在用）与 `latest_binding`（历史最新）严格区分 | ✅ |
| 绑定为 append-only，重绑前移 `current_binding`，历史行不就地修改 | ✅ |
| Revision restart 后完整度重置（`is_complete=false`、`bound=0`） | ✅ |
| restart 后旧绑定降级为历史，**不自动提升为当前** | ✅ |
| stale Production：旧 Revision 版本仍可绑定，响应带旧 `copy_revision_no` | ✅ |
| 排期门禁：未绑全时 422，绑全后放行 | ✅ |
| 发布门禁：素材完整 + 图稿审核通过后放行 | ✅ |
| published no-op：重复发布不改变 `published_at` | ✅ |

### 2.3 视频号（wechat_channels / clean_master）

| 验收项 | 结果 |
|---|---|
| `expected_asset_role` = `clean_master`，与公众号严格区分 | ✅ |
| 候选项仅含 `clean_master`；`copy_master` 版本不出现在视频号候选 | ✅ |
| `current_binding` / `latest_binding` 语义与公众号一致 | ✅ |
| 视频审核门禁：未绑全时 422，绑全后放行 | ✅ |
| 发布门禁：素材完整 + 视频已审核后放行 | ✅ |

### 2.4 边界与错误语义

| 场景 | 预期 | 结果 |
|---|---|---|
| 错 Revision（非 pinned Revision 的版本） | 422 | ✅ |
| 错 AssetRole（clean_master 绑到公众号） | 422 | ✅ |
| 错 Page（页面不属于 pinned Revision） | 422 | ✅ |
| 伪造字段（`binding_no` / `channel_task_id` / `copy_revision_id`） | 422 | ✅ |
| 缺字段 | 422，且 `errors` 键可供前端渲染 | ✅ |
| 不存在的 `asset_version_id` | 404（不泄露存在性） | ✅ |
| 跨篇目页面 | 404 | ✅ |
| 跨项目 scope | 404 | ✅ |
| 未创建渠道 | 404 | ✅ |
| Binding 的 PUT / PATCH / DELETE | 无对应路由，404 | ✅ |
| 页面刷新状态恢复 | 无客户端持久化，GET 为完整无状态投影；连续两次 GET 响应一致 | ✅ |

### 2.5 自动测试

| 测试项 | 结果 |
|---|---|
| TypeScript（`vue-tsc --noEmit`） | 零错误 |
| Vite Build | 成功，830 modules |
| W09 集成验收 | **9 passed / 125 assertions** |
| ChannelAssetBinding 专项 | 7 passed / 152 assertions |
| ChannelTask 专项 | 38 passed / 477 assertions |
| 完整 PHP 测试套件 | **210 passed / 2195 assertions** |
| Pint 格式校验 | 166 files PASS |
| SQLite 迁移 / 回滚 / 重新迁移 | 全部通过 |
| 正式 Main 工作区 | 干净；`git fsck --full` 无缺失对象 |

---

## 三、遗留问题

| # | 问题 | 类型 | 影响 | 处理 |
|---|---|---|---|---|
| 1 | `docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` 第 5 行称未改动 `tests/Feature/**`，实际新增了 `W09IntegrationVerificationTest.php` | 文档措辞误差 | 不影响运行与集成 | 已登记为遗留事项（ID `rM4YQq`，负责人 邝耀明，优先级 low），待 main 有其它改动时顺带修正 |

**除上述一项外，无其它遗留问题。**

---

## 四、客户确认项

本次为内部研发迭代任务，未涉及对外交付承诺，无客户确认项。

如后续需要将本任务成果作为对外交付内容，建议在输出前复核：

- 是否包含内部敏感信息（成本、人员安排、未确认承诺、内部风险判断）
- 内部版与客户版材料应分开存放与使用

---

## 五、建议的后续动作

1. **修正文档措辞**（遗留项 1）：下次 main 有改动时顺带处理，无需单独开分支。
2. **同步三份中央共享文档**：按原分工由 ChatGPT 统一同步，本任务未触碰。
3. **归档**：建议将本文档与 `docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` 一并上传项目资料库，供后续任务复用。
4. **下一任务衔接**：DEV-D10.CLEAN 与 Auth Lite 均未在本次范围内，可按需另行排期。
