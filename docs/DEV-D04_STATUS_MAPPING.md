# DEV-D04｜四维状态词汇审计与历史状态映射规范

> 分支：`doubao/DEV-D04-status-mapping`
> 生成日期：2026-10-01
> 适用范围：历史 Fixture 状态值 → 产品正式状态词的统一映射
> 本文档仅做审计与建议，不修改任何正式代码或 Fixture。

---

## 一、产品正式状态基线（V1.0）

四个维度必须保持独立，不允许互相推断：

### Copy（文案状态）
| 正式值 | 含义 |
| --- | --- |
| `not_started` | 尚未开始文案撰写 |
| `editing` | 文案编辑中 |
| `pending_confirmation` | 待用户确认 |
| `confirmed` | 用户已确认 |

### Artwork（图稿状态）
| 正式值 | 含义 |
| --- | --- |
| `not_applicable` | 不适用（无需图稿） |
| `not_started` | 尚未开始制作 |
| `in_progress` | 制作中 |
| `pending_review` | 待审核 / 待验收 |
| `approved` | 已通过 / 已定稿 |

### Video（视频状态）
| 正式值 | 含义 |
| --- | --- |
| `not_applicable` | 不适用（无视频任务） |
| `not_started` | 尚未开始制作 |
| `in_progress` | 制作中 |
| `pending_review` | 待审核 / 待验收 |
| `approved` | 已通过 / 已验收 |

### Publish（发布状态）
| 正式值 | 含义 |
| --- | --- |
| `unpublished` | 未发布 |
| `scheduled` | 已排期 |
| `published` | 已发布 |

---

## 二、当前正式代码状态值审计

> 来源：DEV-002 当前 Migration / Factory / Test / 模型默认值。仅读取记录，不修改。

| 当前代码值 | 所在表 / 字段 | 产品正式目标值 | 是否冲突 | 说明 |
| --- | --- | --- | --- | --- |
| `draft` | ContentItem.copy_status | `editing` 或 `pending_confirmation` | ⚠️ 冲突 | 代码用 `draft`，产品规范中无此词。需统一为 `editing` 或 `not_started` |
| `not_published` | ChannelTask.publish_status | `unpublished` | ⚠️ 冲突 | 代码用 `not_published`，产品规范为 `unpublished`。词不一致 |
| `null` | ChannelTask.video_status | `not_applicable` | ⚠️ 冲突 | 代码用 null 表示无视频任务，产品规范为 `not_applicable`。建议用枚举而非 null |
| （其他待主程补充） |  |  |  |  |

### 冲突汇总

| # | 冲突项 | 影响范围 | 必须在 Importer 前解决？ |
| --- | --- | --- | --- |
| 1 | `copy_status = draft` vs 产品 `editing` / `not_started` | ContentItem | ✅ 必须 |
| 2 | `publish_status = not_published` vs 产品 `unpublished` | ChannelTask | ✅ 必须 |
| 3 | `video_status = null` vs 产品 `not_applicable` | ChannelTask | ✅ 必须 |

---

## 三、历史 Fixture 状态值审计

> 来源：DEV-01 Fixture 中四个维度的所有状态值。

### Copy 维度（文案状态）

| 历史值 | 来源 | 出现次数 |
| --- | --- | --- |
| `copy_confirmed = true`（布尔） | ContentItem.copy_confirmed | 4 篇全部 |

> 注意：历史 Fixture 用布尔值 `copy_confirmed` 表示文案是否已确认，没有中间状态（如 editing / pending_confirmation）。

### Artwork 维度（图稿状态）

| 历史值 | 来源 | 出现篇目 |
| --- | --- | --- |
| `proof_rendered_pending_final_acceptance` | ContentItem.artwork_status | CI-LIFE-001（出门磨蹭） |
| `final_artwork_confirmed` | ContentItem.artwork_status | CI-EMO-001（积木倒了） |
| `not_produced` | ContentItem.artwork_status | CI-SOC-001（姐弟玩车） |
| `candidates_in_review_not_finalized` | ContentItem.artwork_status | CI-GROW-001（纸箱水果店） |

### Video 维度（视频状态）

| 历史值 | 来源 | 出现篇目 |
| --- | --- | --- |
| `dynamic_story_package_executable_pending_acceptance` | ContentItem.video_status | CI-LIFE-001（出门磨蹭） |
| `v7_reedit_task_order_pending` | ContentItem.video_status | CI-EMO-001（积木倒了） |
| `not_started` | ContentItem.video_status | CI-SOC-001（姐弟玩车） |
| `not_started` | ContentItem.video_status | CI-GROW-001（纸箱水果店） |

### Publish 维度（发布状态）

| 历史值 | 来源 | 出现篇目 |
| --- | --- | --- |
| `not_published` | ContentItem.publish_status | CI-LIFE-001 / CI-SOC-001 / CI-GROW-001 |
| `artwork_finalized_platform_publish_unverified` | ContentItem.publish_status | CI-EMO-001（积木倒了） |

### ChannelTask 级别状态

| 历史值 | 来源 | 说明 |
| --- | --- | --- |
| `not_published` | ChannelTask.status | 大部分渠道任务 |
| `artwork_finalized_publish_unverified` | ChannelTask.status | 积木倒了公众号 |

---

## 四、历史状态 → 正式状态映射矩阵

### Copy 维度

| 历史状态 | 属于维度 | 建议正式状态 | 可自动映射？ | 需人工确认？ | 原因 |
| --- | --- | --- | --- | --- | --- |
| `copy_confirmed = true` | Copy | `confirmed` | ✅ 是 | ❌ 否 | 4 篇全部已确认文案 |
| `copy_confirmed = false`（无） | Copy | `not_started` 或 `editing` | — | — | 历史 Fixture 中无 false 案例 |

### Artwork 维度

| 历史状态 | 属于维度 | 建议正式状态 | 可自动映射？ | 需人工确认？ | 原因 |
| --- | --- | --- | --- | --- |
| `not_produced` | Artwork | `not_started` | ✅ 是 | ❌ 否 | 尚未开始制作 |
| `candidates_in_review_not_finalized` | Artwork | `pending_review` | ✅ 是 | ❌ 否 | 候选在审，未交付，属于待审核 |
| `proof_rendered_pending_final_acceptance` | Artwork | `pending_review` | ✅ 是 | ❌ 否 | 已出样张但待最终验收，属于待审核 |
| `final_artwork_confirmed` | Artwork | `approved` | ✅ 是 | ❌ 否 | 已发布定稿，用户已确认 |

### Video 维度

| 历史状态 | 属于维度 | 建议正式状态 | 可自动映射？ | 需人工确认？ | 原因 |
| --- | --- | --- | --- | --- | --- |
| `not_started` | Video | `not_started` | ✅ 是 | ❌ 否 | 直接对应 |
| `dynamic_story_package_executable_pending_acceptance` | Video | `pending_review` | ✅ 是 | ❌ 否 | 制作包可执行但待验收，属于待审核 |
| `v7_reedit_task_order_pending` | Video | `pending_review` | ✅ 是 | ⚠️ 建议确认 | V7 是重剪任务单，待验收，语义上属于 pending_review；但需确认是否算 in_progress |
| （无视频任务的篇目） | Video | `not_applicable` | ✅ 是 | ❌ 否 | 姐弟玩车、纸箱水果店无视频任务 |

### Publish 维度

| 历史状态 | 属于维度 | 建议正式状态 | 可自动映射？ | 需人工确认？ | 原因 |
| --- | --- | --- | --- | --- | --- |
| `not_published` | Publish | `unpublished` | ✅ 是 | ❌ 否 | 直接对应（词不同但语义同） |
| `artwork_finalized_platform_publish_unverified` | Publish | `unpublished`（保持 unverified 语义） | ⚠️ 保守映射 | ✅ 必须人工确认 | 图稿已定稿但**不能推断已发布**，保持 unpublished 待人工核实 |

---

## 五、当前代码 vs 产品规范冲突清单

| # | 冲突 | 当前代码 | 产品规范 | 影响 | 必须在 Importer 前解决？ |
| --- | --- | --- | --- | --- | --- |
| 1 | copy_status 默认值 | `draft` | 产品无 draft，应为 `not_started` 或 `editing` | ContentItem 创建时默认值不对 | ✅ 必须 |
| 2 | publish_status 默认值 | `not_published` | 产品为 `unpublished` | 枚举值不一致 | ✅ 必须 |
| 3 | video_status 无任务时 | `null` | 产品为 `not_applicable` | null vs 枚举，类型不一致 | ✅ 必须 |
| 4 | 历史 Fixture 用布尔 copy_confirmed | 布尔值 | 产品为 4 态枚举 | 导入时需转换 | ✅ 必须（Importer 内处理） |
| 5 | 历史 Fixture 用字符串长状态 | `proof_rendered_pending_final_acceptance` 等 | 产品为短枚举 | 导入时需映射 | ✅ 必须（Importer 内处理） |

---

## 六、后续主程处理建议

### 必须在历史 Importer 开发前统一

1. **统一 copy_status 枚举**：去掉 `draft`，产品规范为 `not_started / editing / pending_confirmation / confirmed`
2. **统一 publish_status 枚举**：`not_published` → `unpublished`，增加 `scheduled` / `published`
3. **统一 video_status 空值处理**：不用 null，用 `not_applicable` 枚举值
4. **Importer 状态映射表**：所有历史长字符串状态按第四章映射矩阵转换为正式短枚举

### 可以后续再处理

1. **Topic / ContentItem UI 状态选择器**：等枚举统一后再做 UI
2. **状态变更历史 / 时间线**：等主程设计
3. **状态自动流转规则**（如 approved 后自动触发 publish 排期）：等产品规划

### 只属于历史数据映射

1. **《积木倒了》发布状态**：保持 `unpublished`，待人工核实后改为 `published`
2. **V7 重剪视频状态**：映射为 `pending_review`，待主程确认是否需要细分

---

## 七、边界与约束

- ❌ 不修改任何正式代码（Migration / Factory / Test / Model）
- ❌ 不修改原始 DEV-D01 Fixture
- ❌ 不自行设计状态自动流转规则
- ✅ 本文档仅做审计与映射建议，供主程统一枚举时参考
- ✅ 四维状态保持独立，不互相推断
- ✅ 《积木倒了》发布状态不推断为 published
