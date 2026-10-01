# DEV-D05｜历史内容正式导入预检清单

> 分支：`doubao/DEV-D05-import-preflight`
> 生成日期：2026-10-01
> 原始 Fixture：`doubao/DEV-D01-content-fixtures`（冻结）
> 状态映射参考：`doubao/DEV-D04-status-mapping` 分支 `docs/DEV-D04_STATUS_MAPPING.md`
> 该规范当前属于历史迁移审计依据；最终正式状态定义以后续 DEV-004 合入 main 的代码与文档为准。
> 本文档仅做导入前审计，不创建 Seeder / Importer / Migration / Model。

---

## 一、预检范围

| 篇目 ID | 篇名 | 小栏目 | 页数 |
| --- | --- | --- | --- |
| CI-LIFE-001 | 孩子出门总磨蹭 | 生活小能力 | 10 |
| CI-EMO-001 | 积木倒了，孩子哭了 | 看见小情绪 | 9 |
| CI-SOC-001 | 弟弟想玩车，姐姐还没玩完 | 相处小智慧 | 10 |
| CI-GROW-001 | 一只纸箱，开了家水果店 | 原来在长大 | 8 |
| **合计** | | | **37 页** |

---

## 二、READY 状态定义（重要）

**READY 表示：数据结构和映射关系已具备导入条件。**

但正式 Importer 执行仍必须满足：**DEV-004 状态规范已经合入正式 main。**

在 DEV-004 完成前，不得把 READY 解读为"可以立即执行生产数据库导入"。

---

## 三、Project 数据来源说明

| 字段 | 值 | 来源 |
| --- | --- | --- |
| legacy ID | `PRJ-QN-001` | 历史 Fixture |
| name | 青柠育见 | 历史 Fixture |
| slug | `qingning-yujian` | **固定迁移补充值**，非历史 Fixture 原始字段 |

> 原始 DEV-D01 Fixture 中 Project 无 slug 字段。
> slug 是正式数据库必填字段，由迁移规则显式补充为 `qingning-yujian`。
> Importer 不得自行推断或临时生成其他 slug。
> Project 预检状态为 READY，但 READY 依赖已确认的固定迁移补充值 `qingning-yujian`。
> 不修改原始 Fixture 去补 slug。

---

## 四、旧结构 → 正式结构转换规则

### 旧 Fixture 结构

```
ContentItem
  ├─ ProductionTask (IMG)
  │   └─ ChannelTask (微信公众号)
  └─ ProductionTask (VID)
      └─ ChannelTask (微信视频号)
```

### 正式结构（DEV-002）

```
ContentItem
  └─ 1 个 Shared ProductionTask
      ├─ ChannelTask (wechat_official)
      └─ ChannelTask (wechat_channels)
```

### 旧双 PT 归并规则

| ContentItem | 旧 PT-IMG | 旧 PT-VID | 归并后 |
| --- | --- | --- | --- |
| CI-LIFE-001 | PT-LIFE-001-IMG | PT-LIFE-001-VID | **1 个共享 PT**，两个 legacy PT ID 都映射到同一个新 PT.id |
| CI-EMO-001 | PT-EMO-001-IMG | PT-EMO-001-VID | **1 个共享 PT**，两个 legacy PT ID 都映射到同一个新 PT.id |
| CI-SOC-001 | PT-SOC-001-IMG | （无） | **1 个共享 PT** |
| CI-GROW-001 | PT-GROW-001-IMG | （无） | **1 个共享 PT** |

> 字段归属：图稿状态进共享 PT.artwork_status；视频状态下沉到 wechat_channels ChannelTask.video_status。

---

## 五、逐篇预检

---

### 篇 1：《孩子出门总磨蹭》CI-LIFE-001（10 页）

#### 可导入的正式表数据

| 正式表 | 可导入字段 | 数据状态 |
| --- | --- | --- |
| Project | id (legacy→映射), name (Fixture), slug (迁移补充值 `qingning-yujian`) | READY（依赖固定补充值） |
| ContentColumn | id, project_id, name, slug, description | READY |
| Topic | id, project_id, content_column_id, title, description | READY |
| ContentItem | id, project_id, content_column_id, topic_id, title, copy_status | READY |
| ProductionTask（共享） | id, project_id, content_item_id, artwork_status | READY |
| ChannelTask (wechat_official) | id, project_id, production_task_id, channel, video_status, publish_status | READY |
| ChannelTask (wechat_channels) | id, project_id, production_task_id, channel, video_status, publish_status | READY |

#### 预期正式状态值

| 维度 | 预期值 | 来源 |
| --- | --- | --- |
| copy_status | `confirmed` | copy_confirmed = true → confirmed |
| artwork_status | `pending_review` | proof_rendered_pending_final_acceptance → pending_review |
| official channel video_status | `not_applicable` | 公众号图文渠道无视频任务 |
| official channel publish_status | `unpublished` | not_published → unpublished |
| channels video_status | `pending_review` | executable_pending_acceptance → pending_review |
| channels publish_status | `unpublished` | not_published → unpublished |

#### 双渠道收尾句差异

| 渠道 | 收尾句 | ContentPage 状态 |
| --- | --- | --- |
| wechat_official | 你在身边，/"我自己来"更有底气。 | BLOCKED_BY_CONTENT_PAGE |
| wechat_channels | 你等的这一会儿，/是她自己来的底气。 | BLOCKED_BY_CONTENT_PAGE |

> 两句独立，禁止合并覆盖。

#### 页面内容

| 页数 | 状态 |
| --- | --- |
| 10 页 | BLOCKED_BY_CONTENT_PAGE（正式 Schema 中无 ContentPage 表） |

---

### 篇 2：《积木倒了，孩子哭了》CI-EMO-001（9 页）

#### 可导入的正式表数据

| 正式表 | 可导入字段 | 数据状态 |
| --- | --- | --- |
| Project | id (legacy→映射), name (Fixture), slug (迁移补充值 `qingning-yujian`) | READY（依赖固定补充值） |
| ContentColumn | id, project_id, name, slug, description | READY |
| Topic | id, project_id, content_column_id, title, description | READY |
| ContentItem | id, project_id, content_column_id, topic_id, title, copy_status | READY |
| ProductionTask（共享） | id, project_id, content_item_id, artwork_status | READY |
| ChannelTask (wechat_official) | id, project_id, production_task_id, channel, video_status, publish_status | MANUAL_VERIFICATION |
| ChannelTask (wechat_channels) | id, project_id, production_task_id, channel, video_status, publish_status | READY |

#### 预期正式状态值

| 维度 | 预期值 | 来源 |
| --- | --- | --- |
| copy_status | `confirmed` | copy_confirmed = true → confirmed |
| artwork_status | `approved` | final_artwork_confirmed → approved |
| official channel video_status | `not_applicable` | 公众号图文渠道无视频任务 |
| official channel publish_status | `unpublished` | artwork_finalized_platform_publish_unverified → 保守映射为 unpublished |
| channels video_status | `pending_review` | v7_reedit_task_order_pending → pending_review |
| channels publish_status | `unpublished` | not_published → unpublished |

#### 人工待核

> **《积木倒了》公众号实际发布状态**：图稿已定稿，但平台实际发布状态未核实。
> 当前保守映射为 `unpublished`，**不得推断为 published**。
> 待人工确认后更新为 `published`。

#### 页面内容

| 页数 | 状态 |
| --- | --- |
| 9 页 | BLOCKED_BY_CONTENT_PAGE |

---

### 篇 3：《弟弟想玩车，姐姐还没玩完》CI-SOC-001（10 页）

#### 可导入的正式表数据

| 正式表 | 可导入字段 | 数据状态 |
| --- | --- | --- | --- |
| Project | id (legacy→映射), name (Fixture), slug (迁移补充值 `qingning-yujian`) | READY（依赖固定补充值） |
| ContentColumn | id, project_id, name, slug, description | READY |
| Topic | id, project_id, content_column_id, title, description | READY |
| ContentItem | id, project_id, content_column_id, topic_id, title, copy_status | READY |
| ProductionTask（共享） | id, project_id, content_item_id, artwork_status | READY |
| ChannelTask (wechat_official) | id, project_id, production_task_id, channel, video_status, publish_status | READY |
| ChannelTask (wechat_channels) | —— | 无视频任务，不创建 |

#### 预期正式状态值

| 维度 | 预期值 | 来源 |
| --- | --- | --- |
| copy_status | `confirmed` | copy_confirmed = true → confirmed |
| artwork_status | `not_started` | not_produced → not_started |
| official channel video_status | `not_applicable` | 公众号图文渠道无视频任务 |
| official channel publish_status | `unpublished` | not_published → unpublished |
| channels video_status | —— | 无视频渠道任务 |
| channels publish_status | —— | 无视频渠道任务 |

#### 页面内容

| 页数 | 状态 |
| --- | --- |
| 10 页 | BLOCKED_BY_CONTENT_PAGE |

---

### 篇 4：《一只纸箱，开了家水果店》CI-GROW-001（8 页）

#### 可导入的正式表数据

| 正式表 | 可导入字段 | 数据状态 |
| --- | --- | --- |
| Project | id (legacy→映射), name (Fixture), slug (迁移补充值 `qingning-yujian`) | READY（依赖固定补充值） |
| ContentColumn | id, project_id, name, slug, description | READY |
| Topic | id, project_id, content_column_id, title, description | READY |
| ContentItem | id, project_id, content_column_id, topic_id, title, copy_status | READY |
| ProductionTask（共享） | id, project_id, content_item_id, artwork_status | READY |
| ChannelTask (wechat_official) | id, project_id, production_task_id, channel, video_status, publish_status | READY |
| ChannelTask (wechat_channels) | —— | 无视频任务，不创建 |

#### 预期正式状态值

| 维度 | 预期值 | 来源 |
| --- | --- | --- |
| copy_status | `confirmed` | copy_confirmed = true → confirmed |
| artwork_status | `pending_review` | candidates_in_review_not_finalized → pending_review |
| official channel video_status | `not_applicable` | 公众号图文渠道无视频任务 |
| official channel publish_status | `unpublished` | not_published → unpublished |
| channels video_status | —— | 无视频渠道任务 |
| channels publish_status | —— | 无视频渠道任务 |

#### 页面内容

| 页数 | 状态 |
| --- | --- |
| 8 页 | BLOCKED_BY_CONTENT_PAGE |

---

## 六、最终 Preflight 总表

### 按表统计

| 正式表 | 可导入条数 | 状态 |
| --- | --- | --- |
| Project | 1 | READY（依赖固定 slug 补充值） |
| ContentColumn | 6 | READY |
| Topic | 4 | READY |
| ContentItem | 4 | READY |
| ProductionTask（共享） | 4 | READY |
| ChannelTask (wechat_official) | 4 | 3 READY + 1 MANUAL_VERIFICATION |
| ChannelTask (wechat_channels) | 2 | READY |
| ContentPage | 37 | BLOCKED_BY_CONTENT_PAGE |

### 按篇目统计

| 篇目 | 核心数据 | 页面内容 | 人工待核 |
| --- | --- | --- | --- |
| 孩子出门总磨蹭 | READY | BLOCKED_BY_CONTENT_PAGE | —— |
| 积木倒了，孩子哭了 | READY | BLOCKED_BY_CONTENT_PAGE | ⚠️ 公众号发布状态待人工核实 |
| 弟弟想玩车 | READY | BLOCKED_BY_CONTENT_PAGE | —— |
| 纸箱水果店 | READY | BLOCKED_BY_CONTENT_PAGE | —— |

---

## 七、Codex Importer 开发指引

### 现在具备导入条件的（READY，但需 DEV-004 合入 main 后方可执行）

- Project / ContentColumn / Topic / ContentItem / ProductionTask / ChannelTask 的核心字段
- 所有状态值按 DEV-04 状态映射规范转换为正式枚举（参考：`doubao/DEV-D04-status-mapping` 分支）
- legacy ID 通过导入期映射表解析为 bigint 外键（详见 DEV-D03）
- 结构性错误触发整批事务回滚
- **注意：READY 不等于可以立即执行生产导入，必须等 DEV-004 状态规范合入 main 后**

### 必须等 ContentPage 建表后才能导的（BLOCKED_BY_CONTENT_PAGE）

- 37 页逐页文案（标题、副标题、正文小字、收尾句）
- 逐页 page_type、page_no、column_label 等元数据
- 双渠道不同收尾句（待 ContentPage 或 ChannelTask 扩展字段建模后导入）

### 必须人工确认后才能定的（MANUAL_VERIFICATION）

- 《积木倒了》公众号实际发布状态：当前保守映射为 `unpublished`，待人工核实后更新

---

## 八、边界与约束

- ❌ 不创建 ContentPage 正式表 / Migration / Model
- ❌ 不创建 Seeder / Importer
- ❌ 不修改原始 DEV-D01 Fixture
- ❌ 不制造第二份"正式数据源"——所有历史数据仍以 Fixture 为唯一来源
- ❌ 不允许从 Artwork 状态推断 Publish 状态
- ❌ Project.slug 不来自历史 Fixture，是固定迁移补充值 `qingning-yujian`，Importer 不得自行生成
- ✅ 37 页统一标记为 BLOCKED_BY_CONTENT_PAGE，保留来源引用
- ✅ 《积木倒了》发布状态保持 unpublished + manual verification required
- ✅ 旧双 PT 归并为单共享 PT 的规则已明确
