# DEV-D01 → 新架构 Schema 映射说明

> 分支：`doubao/DEV-D01-schema-mapping`
> 生成日期：2026-10-01
> 原始 Fixture 分支：`doubao/DEV-D01-content-fixtures`（冻结，不修改）
> 本文档仅做映射说明，不创建 Seeder / Migration / Model。

---

## 一、新旧架构对比

### 旧 Fixture 结构（DEV-D01 原始）

```
Project
  └─ ContentColumn（小栏目）
      └─ Topic（选题）
          └─ ContentItem（篇目）
              ├─ ContentPage × N（逐页）
              ├─ ProductionTask (image_carousel)  ← 图文制作任务
              │   └─ ChannelTask (wechat_official)
              └─ ProductionTask (dynamic_story_video)  ← 视频制作任务
                  └─ ChannelTask (wechat_channels)
```

**旧结构问题**：图文和视频各自拥有独立的 ProductionTask，图稿状态和视频状态散落在两个任务对象里，后续资产体系无法统一挂载。

### 新正式架构（DEV-002 定稿后）

```
Project
  └─ ContentColumn（小栏目）
      └─ Topic（选题）
          └─ ContentItem（篇目）
              ├─ ContentPage × N（逐页）
              └─ ProductionTask（共享，唯一）  ← 承载图文图稿、文案、排版等资产
                  ├─ ChannelTask (wechat_official)  ← 公众号渠道
                  └─ ChannelTask (wechat_channels)  ← 视频号渠道（含视频制作状态扩展）
```

**核心变更**：
- 每个 ContentItem 只保留 **一个** 共享 ProductionTask
- 图文图稿状态进入共享 ProductionTask / 后续资产体系
- 视频制作、配音、剪辑等状态下沉到 **视频号 ChannelTask** 的后续扩展结构
- 公众号、视频号平级为两个 ChannelTask，共同挂在同一个 ProductionTask 下

---

## 二、逐篇映射说明（4 篇 / 37 页）

### 篇 1：《孩子出门总磨蹭》CI-LIFE-001（生活小能力，10 页）

| 旧对象 | 新归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-LIFE-001 | ContentItem（不变） | 主数据直接迁移 |
| ContentPage × 10 | ContentPage × 10（不变） | 逐页文案直接迁移 |
| PT-LIFE-001-IMG（图文制作） | **ProductionTask（共享）** | 图稿状态 `proof_rendered_pending_final_acceptance` 迁入共享任务 |
| PT-LIFE-001-VID（动态视频） | **wechat_channels ChannelTask 扩展结构** | 视频状态 `executable_pending_acceptance` 不再独立为 ProductionTask |
| CT-LIFE-001-WX（公众号） | ChannelTask (channel=wechat_official) | 直接迁移，收尾句不变 |
| CT-LIFE-001-SPH（视频号） | ChannelTask (channel=wechat_channels) | 直接迁移，视频制作状态挂在本渠道任务的扩展字段 |

**双渠道收尾句差异（必须保留，禁止覆盖）**：

| 渠道 | 收尾句 | 来源 | 保存位置 |
| --- | --- | --- | --- |
| 公众号图文 | 你在身边，/"我自己来"更有底气。 | 历史固定栏目句 | wechat_official ChannelTask.closing_line |
| 视频号动态 | 你等的这一会儿，/是她自己来的底气。 | 本篇专属（动态视频版） | wechat_channels ChannelTask.closing_line |

> 两句都含"底气"但整句及故事落点不同；在新结构中分属两个 ChannelTask，天然独立，不会互相覆盖。

---

### 篇 2：《积木倒了，孩子哭了》CI-EMO-001（看见小情绪，9 页）

| 旧对象 | 新归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-EMO-001 | ContentItem（不变） | 主数据直接迁移 |
| ContentPage × 9 | ContentPage × 9（不变） | 逐页文案直接迁移 |
| PT-EMO-001-IMG（图文制作） | **ProductionTask（共享）** | 图稿状态 `final_artwork_confirmed` 迁入共享任务 |
| PT-EMO-001-VID（V7 重剪） | **wechat_channels ChannelTask 扩展结构** | 视频状态 `reedit_task_order_pending` 挂在视频号渠道任务 |
| CT-EMO-001-WX（公众号） | ChannelTask (channel=wechat_official) | 直接迁移 |
| CT-EMO-001-SPH（视频号） | ChannelTask (channel=wechat_channels) | 直接迁移 |

**发布状态保持 unknown / 待人工核实**：
- 旧字段 `publish_status = artwork_finalized_platform_publish_unverified`
- 新结构中继续保留为 `publish_status = unverified`，**不得**因图稿已定稿就推断已发布。
- 该项列入人工待核清单，待主程或运营确认后再更新。

---

### 篇 3：《弟弟想玩车，姐姐还没玩完》CI-SOC-001（相处小智慧，10 页）

| 旧对象 | 新归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-SOC-001 | ContentItem（不变） | 主数据直接迁移 |
| ContentPage × 10 | ContentPage × 10（不变） | 逐页文案直接迁移 |
| PT-SOC-001-IMG（图文制作） | **ProductionTask（共享）** | 图稿状态 `not_produced` 迁入共享任务 |
| （无视频制作任务） | **无 wechat_channels ChannelTask** | 本篇只有公众号渠道，不创建视频号渠道任务 |
| CT-SOC-001-WX（公众号） | ChannelTask (channel=wechat_official) | 直接迁移 |

> 状态机边界：文案已确认（2026-09-30）≠ 图稿已定稿 ≠ 视频已启动。新结构中继续保持四状态独立。

---

### 篇 4：《一只纸箱，开了家水果店》CI-GROW-001（原来在长大，8 页）

| 旧对象 | 新归属 | 说明 |
| --- | --- | --- |
| ContentItem CI-GROW-001 | ContentItem（不变） | 主数据直接迁移 |
| ContentPage × 8 | ContentPage × 8（不变） | 逐页文案直接迁移 |
| PT-GROW-001-IMG（图文制作） | **ProductionTask（共享）** | 图稿状态 `candidates_in_review_not_finalized` 迁入共享任务 |
| （无视频制作任务） | **无 wechat_channels ChannelTask** | 本篇只有公众号渠道，不创建视频号渠道任务 |
| CT-GROW-001-WX（公众号） | ChannelTask (channel=wechat_official) | 直接迁移 |

> 第05页有单张复工验收记录，但整套图稿未正式交付，新结构中继续保持 `candidates_in_review_not_finalized`。

---

## 三、字段映射总表

### 3.1 Project / ContentColumn / Topic / ContentItem 层

| 旧字段 | 新字段 / 未来模块 | 是否可自动迁移 | 是否需要人工确认 |
| --- | --- | --- | --- |
| Project.id | Project.id | ✅ 直接迁移 | ❌ |
| Project.name / brand / operator | Project.name / brand / operator | ✅ 直接迁移 | ❌ |
| Project.platforms | Project.platforms | ✅ 直接迁移 | ❌ |
| Column.id / name / slug | ContentColumn.id / name / slug | ✅ 直接迁移 | ❌ |
| Column.historical_closing_line | ContentColumn.default_closing_line | ⚠️ 字段名待对齐 | ❌ |
| Column.has_confirmed_articles | ContentColumn.has_confirmed_articles | ✅ 直接迁移 | ❌ |
| Topic.id / title / slug | Topic.id / title / slug | ✅ 直接迁移 | ❌ |
| Topic.core_question | Topic.core_question | ✅ 直接迁移 | ❌ |
| Topic.status | Topic.status | ✅ 直接迁移 | ❌ |
| ContentItem.id / title / page_count | ContentItem.id / title / page_count | ✅ 直接迁移 | ❌ |
| ContentItem.copy_confirmed | ContentItem.copy_confirmed | ✅ 直接迁移 | ❌ |
| ContentItem.copy_confirmed_date | ContentItem.copy_confirmed_at | ⚠️ 字段名待对齐 | ❌ |
| ContentItem.script_source_path | ContentItem.script_source_path | ✅ 直接迁移 | ❌ |
| ContentItem.final_copy_source | ContentItem.final_copy_source | ✅ 直接迁移 | ❌ |

### 3.2 ProductionTask 层（旧两个 → 新一个共享）

| 旧字段 | 新字段 / 未来模块 | 是否可自动迁移 | 是否需要人工确认 |
| --- | --- | --- | --- |
| PT-LIFE-001-IMG / PT-EMO-001-IMG / PT-SOC-001-IMG / PT-GROW-001-IMG | **ProductionTask（共享，每个 ContentItem 一个）** | ⚠️ 合并迁移，需主程确认合并规则 | ⚠️ 需确认 |
| ProductionTask.type = image_carousel | ProductionTask.primary_format = image_carousel | ⚠️ 语义对齐 | ❌ |
| ProductionTask.target_spec | ProductionTask.primary_asset_spec | ⚠️ 字段名待对齐 | ❌ |
| ProductionTask.status（图稿相关） | ProductionTask.artwork_status / asset_status | ⚠️ 拆分到资产体系 | ⚠️ 需确认枚举 |
| ProductionTask.artifact_path | ProductionTask.primary_asset_path | ⚠️ 字段名待对齐 | ❌ |
| ~~ProductionTask.type = dynamic_story_video~~ | **删除**（视频任务下沉到 ChannelTask） | ✅ 自动下沉 | ❌ |
| ~~PT-LIFE-001-VID / PT-EMO-001-VID~~ | **wechat_channels ChannelTask.video_production 扩展结构** | ⚠️ 迁移到渠道任务扩展 | ⚠️ 需确认扩展结构 |
| ~~ProductionTask.closing_line_override（视频版收尾句）~~ | **ChannelTask.closing_line（wechat_channels）** | ✅ 直接迁移 | ❌ |

### 3.3 ChannelTask 层

| 旧字段 | 新字段 / 未来模块 | 是否可自动迁移 | 是否需要人工确认 |
| --- | --- | --- | --- |
| ChannelTask.channel = 微信公众号 | ChannelTask.channel = wechat_official | ✅ 枚举值对齐 | ❌ |
| ChannelTask.channel = 微信视频号 | ChannelTask.channel = wechat_channels | ✅ 枚举值对齐 | ❌ |
| ChannelTask.format | ChannelTask.format | ✅ 直接迁移 | ❌ |
| ChannelTask.status | ChannelTask.publish_status | ⚠️ 字段名待对齐 | ❌ |
| ChannelTask.status_detail | ChannelTask.publish_status_detail | ⚠️ 字段名待对齐 | ❌ |
| ChannelTask.closing_line_used | ChannelTask.closing_line | ⚠️ 字段名待对齐 | ❌ |
| ChannelTask.closing_line_source | ChannelTask.closing_line_source | ✅ 直接迁移 | ❌ |
| （旧 PT-VID 中的视频状态） | ChannelTask.video_production.status（wechat_channels 扩展） | ⚠️ 新增扩展结构 | ⚠️ 需主程设计 |
| （旧 PT-VID 中的配音/剪辑/时码） | ChannelTask.video_production.* （未来扩展） | ❌ 暂不迁移 | ⚠️ 待人工补充 |

### 3.4 ContentPage 层

| 旧字段 | 新字段 / 未来模块 | 是否可自动迁移 | 是否需要人工确认 |
| --- | --- | --- | --- |
| Page.id / content_item_id / page_no | Page.id / content_item_id / page_no | ✅ 直接迁移 | ❌ |
| Page.page_type | Page.page_type | ✅ 直接迁移 | ❌ |
| Page.cover_title / cover_subtitle | Page.cover_title / cover_subtitle | ✅ 直接迁移 | ❌ |
| Page.content_title / small_text | Page.content_title / small_text | ✅ 直接迁移 | ❌ |
| Page.closing_line | Page.closing_line | ✅ 直接迁移 | ❌ |
| Page.closing_line_version | Page.closing_line_version | ✅ 直接迁移 | ❌ |
| Page.closing_source | Page.closing_source | ✅ 直接迁移 | ❌ |

---

## 四、状态独立性约束（新架构下继续遵守）

| 状态维度 | 新结构中的位置 | 约束 |
| --- | --- | --- |
| 文案确认 | ContentItem.copy_confirmed | 文案确认 ≠ 图稿定稿 |
| 图稿 / 资产制作 | ProductionTask.artwork_status | 图稿定稿 ≠ 视频验收 |
| 视频制作 | wechat_channels ChannelTask.video_production.status | 视频验收 ≠ 已发布 |
| 渠道发布 | ChannelTask.publish_status | 发布状态独立，不得从前置状态推断 |

---

## 五、人工待核项（继续保留）

1. **《积木倒了，孩子哭了》实际发布状态**：图文发布定稿已确认，但平台实际发布状态未核实。新结构中继续保持 `unverified`，待人工确认后更新。
2. **查重测试样本阈值校准**：8 条测试样本的期望检测级别（exact_match / high_similarity_warning / no_duplicate）需主程根据正式查重引擎能力校准。
3. **视频号 ChannelTask 扩展结构设计**：视频制作状态、配音、剪辑、时码等字段的具体 schema 待主程 DEV-002 定稿。
4. **ProductionTask 与资产体系的挂载方式**：图稿文件、排版样张、生图候选等如何挂到共享 ProductionTask 的资产子体系，待主程设计。

---

## 六、边界与约束

- ❌ 不修改原始 DEV-D01 Fixture（`doubao/DEV-D01-content-fixtures` 分支保持冻结）
- ❌ 不创建 Seeder / Migration / Laravel Model
- ❌ 不重新生成 Fixture 数据
- ✅ 本文档仅为映射说明，供主程 DEV-002 数据库 Schema 定稿后参考
- ✅ 所有字段迁移标注了"是否可自动迁移"和"是否需要人工确认"
- ✅ 《孩子出门总磨蹭》双渠道收尾句差异在新结构中天然独立，不会覆盖
- ✅ 《积木倒了》发布状态继续保持 unknown / 待人工核实
