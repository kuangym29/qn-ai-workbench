# DEV-D03｜历史 Fixture ID → 正式数据库主键映射规范

> 分支：`doubao/DEV-D03-legacy-id-mapping`
> 生成日期：2026-10-01
> 适用范围：DEV-01 Fixture 导入正式数据库（DEV-002 Schema）
> 本文档仅做规范说明，不创建 Seeder / Importer / Migration / Model。

---

## 一、背景与问题

### 历史 Fixture ID（可读字符串）

DEV-01 Fixture 全部使用可读字符串 ID，便于人工查阅：

| 表 | 历史 ID 示例 | 数量 |
| --- | --- | --- |
| Project | `PRJ-QN-001` | 1 |
| ContentColumn | `COL-LIFE-001` / `COL-EMO-001` / `COL-SOC-001` / `COL-GROW-001` / `COL-DAILY-001` / `COL-PARENT-001` | 6 |
| Topic | `TOPIC-LIFE-001` / `TOPIC-EMO-001` / `TOPIC-SOC-001` / `TOPIC-GROW-001` | 4 |
| ContentItem | `CI-LIFE-001` / `CI-EMO-001` / `CI-SOC-001` / `CI-GROW-001` | 4 |
| ProductionTask（旧双 PT） | `PT-LIFE-001-IMG` / `PT-LIFE-001-VID` / `PT-EMO-001-IMG` / `PT-EMO-001-VID` / `PT-SOC-001-IMG` / `PT-GROW-001-IMG` | 6 |
| ChannelTask | `CT-LIFE-001-WX` / `CT-LIFE-001-SPH` / `CT-EMO-001-WX` / `CT-EMO-001-SPH` / `CT-SOC-001-WX` / `CT-GROW-001-WX` | 6 |

### 正式数据库主键（Laravel bigint 自增）

DEV-002 正式 Schema 全部使用 `id` 字段为 bigint 自增主键：

| 正式表 | 主键类型 |
| --- | --- |
| Project | bigint, auto_increment |
| ContentColumn | bigint, auto_increment |
| Topic | bigint, auto_increment |
| ContentItem | bigint, auto_increment |
| ProductionTask | bigint, auto_increment |
| ChannelTask | bigint, auto_increment |

### 核心矛盾

**不能把历史字符串 ID 直接写入 bigint 主键字段。**

---

## 二、映射原则

1. **不修改正式数据库主键类型**——正式表继续使用 bigint 自增。
2. **不给正式表增加 `legacy_id` 字段**——除非以后主程另行决定，当前不做。
3. **原始 Fixture 中的字符串 ID 保持不变**——继续作为历史数据引用键，冻结在 `doubao/DEV-D01-content-fixtures` 分支。
4. **Importer 运行时建立临时映射表**——导入过程中在内存里维护 legacy ID → 新 bigint ID 的对照。
5. **子级对象通过映射表解析正式外键**——不能把字符串 ID 写入 bigint 外键字段。
6. **父级找不到就报错停止**——如果某条子级记录的父级 legacy ID 不在映射表中，停止该条导入并记录错误，**不允许猜测、不允许跳过、不允许写 0**。

---

## 三、导入期映射表（Importer 内存结构）

Importer 运行时按以下顺序逐级导入，每导入一级就把 legacy ID → 新 bigint ID 存入映射表：

| 导入顺序 | 正式表 | 映射表键 → 值 |
| --- | --- | --- |
| 1 | Project | `PRJ-QN-001` → 新 `project.id` |
| 2 | ContentColumn | `COL-LIFE-001` → 新 `content_column.id` |
| 3 | Topic | `TOPIC-LIFE-001` → 新 `topic.id` |
| 4 | ContentItem | `CI-LIFE-001` → 新 `content_item.id` |
| 5 | ProductionTask（共享） | 见下文「旧双 PT 合并规则」 |
| 6 | ChannelTask | `CT-LIFE-001-WX` → 新 `channel_task.id` |

### 映射表示例（导入完成后内存状态）

```
legacy_project_map = {
  "PRJ-QN-001": 1
}

legacy_column_map = {
  "COL-LIFE-001": 1,
  "COL-EMO-001": 2,
  "COL-SOC-001": 3,
  "COL-GROW-001": 4,
  "COL-DAILY-001": 5,
  "COL-PARENT-001": 6
}

legacy_topic_map = {
  "TOPIC-LIFE-001": 1,
  "TOPIC-EMO-001": 2,
  "TOPIC-SOC-001": 3,
  "TOPIC-GROW-001": 4
}

legacy_item_map = {
  "CI-LIFE-001": 1,
  "CI-EMO-001": 2,
  "CI-SOC-001": 3,
  "CI-GROW-001": 4
}

legacy_production_task_map = {
  "PT-LIFE-001-IMG": 1,   # 旧双 PT 合并后，两个旧 ID 指向同一个新 PT
  "PT-LIFE-001-VID": 1,   # 同上
  "PT-EMO-001-IMG": 2,
  "PT-EMO-001-VID": 2,
  "PT-SOC-001-IMG": 3,
  "PT-GROW-001-IMG": 4
}

legacy_channel_task_map = {
  "CT-LIFE-001-WX": 1,
  "CT-LIFE-001-SPH": 2,
  "CT-EMO-001-WX": 3,
  "CT-EMO-001-SPH": 4,
  "CT-SOC-001-WX": 5,
  "CT-GROW-001-WX": 6
}
```

> 注意：以上新 bigint ID 仅为示例，实际值由数据库自增决定。

---

## 四、旧双 ProductionTask 合并规则

### 背景

DEV-01 Fixture 中每个 ContentItem 最多有两个旧 ProductionTask：
- `*-IMG`：图文轮播制作任务
- `*-VID`：动态故事视频制作任务

DEV-002 正式 Schema 中每个 ContentItem 只有**一个**共享 ProductionTask。

### 合并规则

| ContentItem | 旧 PT 数量 | 合并后新 PT 数量 | 合并方式 |
| --- | --- | --- |
| CI-LIFE-001（出门磨蹭） | 2 个（IMG + VID） | **1 个** | `PT-LIFE-001-IMG` 和 `PT-LIFE-001-VID` 都指向同一个新 `production_task.id` |
| CI-EMO-001（积木倒了） | 2 个（IMG + VID） | **1 个** | `PT-EMO-001-IMG` 和 `PT-EMO-001-VID` 都指向同一个新 `production_task.id` |
| CI-SOC-001（姐弟玩车） | 1 个（仅 IMG） | **1 个** | 直接映射 |
| CI-GROW-001（纸箱水果店） | 1 个（仅 IMG） | **1 个** | 直接映射 |

### 字段归属

| 旧 PT 字段 | 新归属 |
| --- | --- |
| 图稿相关状态（`artwork_status` 语义） | 新共享 ProductionTask.artwork_status |
| 视频相关状态（`video_status` 语义） | 不进 ProductionTask，下沉到 wechat_channels ChannelTask.video_status |

### 《孩子出门总磨蹭》示例

```
旧：
  PT-LIFE-001-IMG (artwork_status = proof_rendered_pending)
  PT-LIFE-001-VID (video_status = executable_pending)

新（合并后）：
  production_task.id = 1
    content_item_id = 1 (CI-LIFE-001)
    artwork_status = proof_rendered_pending  ← 来自 PT-LIFE-001-IMG

  channel_task.id = 1
    production_task_id = 1
    channel = wechat_official
    video_status = null
    publish_status = not_published

  channel_task.id = 2
    production_task_id = 1
    channel = wechat_channels
    video_status = executable_pending  ← 来自 PT-LIFE-001-VID
    publish_status = not_published
```

> 关键点：两个旧 PT ID 在导入期映射表里都指向同一个新 `production_task.id = 1`，但视频状态不放在 PT 上，而是放在 wechat_channels 渠道任务的 `video_status` 字段上。

---

## 五、ChannelTask 外键解析规则

每个 ChannelTask 导入时，必须通过 `legacy_production_task_map` 解析出正式 `production_task_id`：

| 旧 ChannelTask ID | 旧 production_task_id | 解析后的新 production_task_id |
| --- | --- | --- |
| CT-LIFE-001-WX | PT-LIFE-001-IMG | 查映射表 → 新 PT ID = 1 |
| CT-LIFE-001-SPH | PT-LIFE-001-VID | 查映射表 → 新 PT ID = 1（同一个） |
| CT-EMO-001-WX | PT-EMO-001-IMG | 查映射表 → 新 PT ID = 2 |
| CT-EMO-001-SPH | PT-EMO-001-VID | 查映射表 → 新 PT ID = 2（同一个） |
| CT-SOC-001-WX | PT-SOC-001-IMG | 查映射表 → 新 PT ID = 3 |
| CT-GROW-001-WX | PT-GROW-001-IMG | 查映射表 → 新 PT ID = 4 |

> 公众号和视频号 ChannelTask 分别映射到同一个新 ProductionTask，这是正式架构的设计要求。

---

## 六、错误处理规则

| 场景 | 处理方式 |
| --- | --- |
| 子级记录的父级 legacy ID 不在映射表中 | **停止该条导入**，记录错误日志，导入继续下一条 |
| 旧 PT-VID 对应的 ContentItem 没有 wechat_channels ChannelTask | 正常，视频状态无地方落，仅记录 warning |
| 旧 PT-IMG 对应的 ContentItem 没有 wechat_official ChannelTask | 正常，记录 warning，不报错 |
| 映射表中出现重复 legacy ID | **导入失败**，Fixture 数据有问题，需人工核查 |

---

## 七、与其他文档的关系

| 文档 | 作用 |
| --- | --- |
| `docs/DEV-D01_SCHEMA_MAPPING.md` | 字段级映射：旧字段 → 正式字段，哪些可导、哪些待建模 |
| `docs/DEV-D03_LEGACY_ID_MAPPING.md`（本文档） | ID 级映射：legacy 字符串 ID → 新 bigint ID，导入期如何解析外键 |

> 两份文档互补：DEV-D01 讲字段，DEV-D03 讲 ID。Importer 开发时需同时参考。

---

## 八、边界与约束

- ❌ 不创建 Seeder / Importer / Migration / Model
- ❌ 不给正式表增加 legacy_id 字段
- ❌ 不把字符串 ID 写入 bigint 字段
- ❌ 不修改原始 DEV-D01 Fixture
- ✅ 历史字符串 ID 继续作为 Fixture 内部引用键
- ✅ 正式 bigint ID 由数据库自增生成
- ✅ 导入期通过内存映射表解析外键
- ✅ 父级找不到则停止该条导入并报错，不猜测
