# DEV-D01 → 正式 Schema 映射说明（V2）

> 分支：`doubao/DEV-D01-schema-mapping`
> 更新日期：2026-10-01
> 原始 Fixture 分支：`doubao/DEV-D01-content-fixtures`（冻结，不修改）
> 正式 Schema 基线：DEV-002 已定稿，未进入 DEV-002 的能力留待后续独立任务
> 本文档仅做映射说明，不创建 Seeder / Migration / Model。

---

## 一、正式 Schema 基线（DEV-002 已定稿）

### 已正式存在的表与字段

| 表 | 正式字段 |
| --- | --- |
| **Project** | `id`, `name`, `slug`, `description` |
| **ContentColumn** | `id`, `project_id`, `name`, `slug`, `description`, `sort_order` |
| **Topic** | `id`, `project_id`, `content_column_id`, `title`, `description` |
| **ContentItem** | `id`, `project_id`, `content_column_id`, `topic_id`, `title`, `copy_status` |
| **ProductionTask** | `id`, `project_id`, `content_item_id`, `artwork_status` |
| **ChannelTask** | `id`, `project_id`, `production_task_id`, `channel`, `video_status`, `publish_status` |

### 正式渠道枚举值

| 值 | 含义 |
| --- | --- |
| `wechat_official` | 微信公众号 |
| `wechat_channels` | 微信视频号 |

---

## 二、新旧架构对比

### 旧 Fixture 结构（DEV-D01 原始，已冻结）

```
Project
  └─ ContentColumn（小栏目）
      └─ Topic（选题）
          └─ ContentItem（篇目）
              ├─ ContentPage × N（逐页）
              ├─ ProductionTask (image_carousel)  ← 图文制作任务
              │   └─ ChannelTask (微信公众号)
              └─ ProductionTask (dynamic_story_video)  ← 视频制作任务
                  └─ ChannelTask (微信视频号)
```

### 正式架构（DEV-002 已定稿）

```
Project
  └─ ContentColumn（小栏目）
      └─ Topic（选题）
          └─ ContentItem（篇目）
              └─ ProductionTask（共享，唯一）  ← 图稿状态在此
                  ├─ ChannelTask (wechat_official)  ← 公众号渠道
                  └─ ChannelTask (wechat_channels)  ← 视频号渠道（video_status 在此）
```

**核心转换规则（已确认）**：
- 旧双 ProductionTask → 新单 Shared ProductionTask + 多 ChannelTask
- 每个 ContentItem 只保留 **一个** ProductionTask
- 图稿状态 `artwork_status` 存在共享 ProductionTask 上
- 视频状态 `video_status` 存在 wechat_channels ChannelTask 上
- 发布状态 `publish_status` 存在每个 ChannelTask 上

---

## 三、逐篇映射说明（4 篇 / 37 页）

### 篇 1：《孩子出门总磨蹭》CI-LIFE-001（生活小能力，10 页）

| 旧对象 | 正式归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-LIFE-001 | ContentItem（正式表） | `id`, `title` 直接迁移 |
| ContentPage × 10 | **正式 Schema 尚未承载** | 历史数据已存在，待后续独立任务建表 |
| PT-LIFE-001-IMG（图文制作） | **ProductionTask（共享）** | `artwork_status` 迁入正式字段 |
| PT-LIFE-001-VID（动态视频） | **ChannelTask (wechat_channels)** | `video_status` 迁入正式字段，不再独立为 ProductionTask |
| CT-LIFE-001-WX（公众号） | ChannelTask (channel=wechat_official) | `publish_status` 直接迁移 |
| CT-LIFE-001-SPH（视频号） | ChannelTask (channel=wechat_channels) | `publish_status` 直接迁移 |

**双渠道收尾句差异（必须保留，禁止覆盖）**：

| 渠道 | 旧收尾句 | 正式 Schema 状态 |
| --- | --- | --- |
| 公众号图文（wechat_official） | 你在身边，/"我自己来"更有底气。 | **正式 Schema 尚未承载**——无 `closing_line` 字段 |
| 视频号动态（wechat_channels） | 你等的这一会儿，/是她自己来的底气。 | **正式 Schema 尚未承载**——无 `closing_line` 字段 |

> 两句都含"底气"但整句及故事落点不同。当前正式 Schema 无收尾句字段，历史数据完整保留在 Fixture 中；收尾句归属待后续独立任务建模时确认，**不得合并覆盖**。

---

### 篇 2：《积木倒了，孩子哭了》CI-EMO-001（看见小情绪，9 页）

| 旧对象 | 正式归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-EMO-001 | ContentItem（正式表） | `id`, `title` 直接迁移 |
| ContentPage × 9 | **正式 Schema 尚未承载** | 历史数据已存在，待后续独立任务建表 |
| PT-EMO-001-IMG（图文制作） | **ProductionTask（共享）** | `artwork_status` 迁入正式字段 |
| PT-EMO-001-VID（V7 重剪） | **ChannelTask (wechat_channels)** | `video_status` 迁入正式字段 |
| CT-EMO-001-WX（公众号） | ChannelTask (channel=wechat_official) | `publish_status` 直接迁移 |
| CT-EMO-001-SPH（视频号） | ChannelTask (channel=wechat_channels) | `publish_status` 直接迁移 |

**发布状态保持待人工核实**：
- 旧值：`artwork_finalized_platform_publish_unverified`
- 正式字段：`ChannelTask.publish_status`
- 当前值：**保持 unverified 语义**，不得因图稿已定稿就推断已发布
- 该项列入人工待核清单，待主程或运营确认后再更新

---

### 篇 3：《弟弟想玩车，姐姐还没玩完》CI-SOC-001（相处小智慧，10 页）

| 旧对象 | 正式归属 | 说明 |
| --- | | --- |
| ContentItem CI-SOC-001 | ContentItem（正式表） | `id`, `title` 直接迁移 |
| ContentPage × 10 | **正式 Schema 尚未承载** | 历史数据已存在，待后续独立任务建表 |
| PT-SOC-001-IMG（图文制作） | **ProductionTask（共享）** | `artwork_status` 迁入正式字段 |
| （无视频制作任务） | **无 wechat_channels ChannelTask** | 本篇只有公众号渠道，不创建视频号渠道任务 |
| CT-SOC-001-WX（公众号） | ChannelTask (channel=wechat_official) | `publish_status` 直接迁移 |

> 状态机边界：文案状态（`copy_status`）≠ 图稿状态（`artwork_status`）≠ 视频状态（`video_status`）≠ 发布状态（`publish_status`）。正式 Schema 中四状态分属不同表，天然独立。

---

### 篇 4：《一只纸箱，开了家水果店》CI-GROW-001（原来在长大，8 页）

| 旧对象 | 正式归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-GROW-001 | ContentItem（正式表） | `id`, `title` 直接迁移 |
| ContentPage × 8 | **正式 Schema 尚未承载** | 历史数据已存在，待后续独立任务建表 |
| PT-GROW-001-IMG（图文制作） | **ProductionTask（共享）** | `artwork_status` 迁入正式字段 |
| （无视频制作任务） | **无 wechat_channels ChannelTask** | 本篇只有公众号渠道，不创建视频号渠道任务 |
| CT-GROW-001-WX（公众号） | ChannelTask (channel=wechat_official) | `publish_status` 直接迁移 |

> 第05页有单张复工验收记录，但整套图稿未正式交付，`artwork_status` 继续保持候选在审语义。

---

## 四、字段映射总表（按三类划分）

### 4.1 正式已存在 —— 可直接迁移

| 旧 Fixture 字段 | 正式字段 | 表 | 迁移方式 |
| --- | --- | --- | --- |
| Project.id | Project.id | Project | 直接迁移 |
| Project.name | Project.name | Project | 直接迁移 |
| Project.slug | Project.slug | Project | 正式已存在，历史数据待补 |
| Project.description | Project.description | Project | 正式已存在，历史数据待补 |
| ContentColumn.id | ContentColumn.id | ContentColumn | 直接迁移 |
| ContentColumn.name | ContentColumn.name | ContentColumn | 直接迁移 |
| ContentColumn.slug | ContentColumn.slug | ContentColumn | 正式已存在，可迁移 |
| ContentColumn.description | ContentColumn.description | ContentColumn | 正式已存在，可迁移 |
| Topic.id | Topic.id | Topic | 直接迁移 |
| Topic.title | Topic.title | Topic | 直接迁移 |
| ContentItem.id | ContentItem.id | ContentItem | 直接迁移 |
| ContentItem.title | ContentItem.title | ContentItem | 直接迁移 |
| ContentItem.copy_confirmed | **ContentItem.copy_status** | ContentItem | 布尔值映射为状态枚举，待主程确认枚举值 |
| ProductionTask.status（图稿相关） | **ProductionTask.artwork_status** | ProductionTask | 旧枚举值映射为正式枚举，待主程确认 |
| ProductionTask.status（视频相关） | **ChannelTask.video_status** | ChannelTask | 从旧 PT-VID 下沉到 wechat_channels 渠道任务 |
| ChannelTask.channel = 微信公众号 | **ChannelTask.channel = wechat_official** | ChannelTask | 枚举值对齐 |
| ChannelTask.channel = 微信视频号 | **ChannelTask.channel = wechat_channels** | ChannelTask | 枚举值对齐 |
| ChannelTask.status | **ChannelTask.publish_status** | ChannelTask | 旧状态值映射为正式枚举，待主程确认 |

### 4.2 后续待建模 —— 历史数据已存在，正式 Schema 尚未承载

> 以下字段在 DEV-D01 Fixture 中有数据，但 DEV-002 正式 Schema 中**不存在**。建议归属仅为参考，最终由主程在后续独立任务中决定。

| 旧 Fixture 字段 | 历史数据量 | 建议归属（待主程确认） | 备注 |
| --- | --- | --- | --- |
| Project.brand | 1 条 | Project（扩展字段，待建模） | 正式 Schema 无此字段 |
| Project.operator | 1 条 | Project（扩展字段，待建模） | 正式 Schema 无此字段 |
| Project.platforms | 1 条 | Project（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentColumn.sort_order | 0 条 | ContentColumn.sort_order | 正式 Schema 已存在，历史数据中未提供，待补 |
| ContentColumn.historical_closing_line | 6 条 | ContentColumn（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentColumn.has_confirmed_articles | 6 条 | ContentColumn（扩展字段，待建模） | 正式 Schema 无此字段 |
| Topic.slug | 4 条 | Topic（扩展字段，待建模） | 正式 Schema 无此字段 |
| Topic.core_question / description | 4 条 | Topic.description | 正式 Schema 有 description，可迁移 |
| Topic.status / confirmed_date | 4 条 | Topic（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentItem.page_count | 4 条 | ContentItem（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentItem.copy_confirmed_date | 4 条 | ContentItem（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentItem.script_source_path | 4 条 | ContentItem（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentItem.final_copy_source | 4 条 | ContentItem（扩展字段，待建模） | 正式 Schema 无此字段 |
| ContentItem.special_notes | 4 条 | ContentItem（扩展字段，待建模） | 正式 Schema 无此字段 |
| **ContentPage 全部字段** | 37 条 | **独立 ContentPage 表（待建模）** | 正式 Schema 中不存在此表，待后续独立任务建表 |
| ProductionTask.target_spec | 4 条 | ProductionTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ProductionTask.status_detail | 6 条 | ProductionTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ProductionTask.artifact_path | 4 条 | ProductionTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ProductionTask.closing_line_override | 1 条 | ChannelTask（wechat_channels，扩展字段，待建模） | 正式 Schema 无此字段 |
| ChannelTask.format | 6 条 | ChannelTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ChannelTask.status_detail | 6 条 | ChannelTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ChannelTask.closing_line_used | 6 条 | ChannelTask（扩展字段，待建模） | 正式 Schema 无此字段 |
| ChannelTask.closing_line_source | 6 条 | ChannelTask（扩展字段，待建模） | 正式 Schema 无此字段 |

### 4.3 尚未决定 —— 不写入正式 Schema，待主程规划

| 项目 | 说明 |
| --- | --- |
| 资产文件（排版样张、生图候选、定稿图、视频 MP4 等） | 如何挂载到 ProductionTask / ChannelTask，待主程设计资产体系 |
| 视频配音、角色音色、时码、剪辑单 | 属于视频制作细节，待 wechat_channels 渠道扩展建模时决定 |
| 查重引擎与测试样本 | 8 条测试样本如何接入正式查重模块，待后续独立任务 |
| 中央导航 / 栏目收尾句 PNG | 历史素材如何关联，待主程设计 |

---

## 五、状态独立性约束（正式 Schema 下的表现）

| 状态维度 | 正式表.字段 | 约束 |
| --- | --- | --- |
| 文案状态 | ContentItem.copy_status | 文案确认 ≠ 图稿定稿 |
| 图稿状态 | ProductionTask.artwork_status | 图稿定稿 ≠ 视频验收 |
| 视频状态 | ChannelTask.video_status | 视频验收 ≠ 已发布 |
| 发布状态 | ChannelTask.publish_status | 发布状态独立，不得从前置状态推断 |

> 四个状态分属不同表，正式 Schema 层面天然隔离，不存在互相推断的可能。

---

## 六、人工待核项（继续保留）

1. **《积木倒了，孩子哭了》实际发布状态**：图文图稿已定稿，但公众号渠道 `publish_status` 仍为 unverified。待人工确认是否已在公众号实际发布。
2. **查重测试样本阈值校准**：8 条测试样本的期望检测级别需主程根据正式查重引擎能力校准。
3. **旧状态枚举 → 正式枚举的映射关系**：旧 Fixture 中的状态值（如 `proof_rendered_pending_final_acceptance`）需映射为正式 Schema 中的枚举值，待主程提供枚举定义后对齐。
4. **ContentPage 表建模**：37 页逐页文案是核心历史数据，正式 Schema 中尚未建表，待后续独立任务。

---

## 七、边界与约束

- ❌ 不修改原始 DEV-D01 Fixture（`doubao/DEV-D01-content-fixtures` 分支保持冻结）
- ❌ 不创建 Seeder / Migration / Laravel Model
- ❌ 不重新生成 Fixture 数据
- ❌ 不把"后续待建模"或"尚未决定"的字段写成正式 Schema 已存在
- ✅ 本文档仅为映射说明，供主程 DEV-002 正式 Schema 已定稿后参考
- ✅ 4 篇 37 页历史数据在 Fixture 中完整保留，不丢失
- ✅ 《孩子出门总磨蹭》双渠道收尾句差异明确标注，待后续建模时独立保存，不覆盖
- ✅ 《积木倒了》发布状态继续保持 unverified / 待人工核实
- ✅ 旧双 ProductionTask → 新单共享 ProductionTask + 多 ChannelTask 的转换规则已确认
