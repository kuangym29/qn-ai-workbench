# DEV-D03｜历史 Fixture ID → 正式数据库主键映射规范

> 分支：`doubao/DEV-D04-status-mapping`
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

DEV-002 正式 Schema 全部使用 `id` 字段为 bigint 自增主键。

### 核心矛盾

**不能把历史字符串 ID 直接写入 bigint 主键字段。**

---

## 二、映射原则

1. **不修改正式数据库主键类型**——正式表继续使用 bigint 自增。
2. **不给正式表增加 `legacy_id` 字段**——除非以后主程另行决定，当前不做。
3. **原始 Fixture 中的字符串 ID 保持不变**——继续作为历史数据引用键，冻结在 `doubao/DEV-D01-content-fixtures` 分支。
4. **Importer 运行时建立临时映射表**——导入过程中在内存里维护 legacy ID → 新 bigint ID 的对照。
5. **子级对象通过映射表解析正式外键**——不能把字符串 ID 写入 bigint 外键字段。
6. **结构性错误触发整批回滚**——见第六章错误处理规则。

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

---

## 四、旧双 ProductionTask 合并规则

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

---

## 五、ChannelTask 外键解析规则

每个 ChannelTask 导入时，必须通过 `legacy_production_task_map` 解析出正式 `production_task_id`。公众号和视频号 ChannelTask 分别映射到同一个新 ProductionTask。

---

## 六、错误处理规则（V2 修正：事务回滚模式）

### 核心原则

**结构性错误默认中止整批导入并回滚事务。**

不允许"半套数据"——历史数据一次性导入，要么全成功，要么全回滚。

### 结构性错误（触发整批回滚）

| 错误类型 | 示例 | 处理 |
| --- | --- | --- |
| 父级 legacy ID 无法解析 | 子级记录引用了一个不存在的父级 ID | **中止整批导入，回滚事务** |
| legacy ID 重复 | 映射表中出现同一个 legacy ID 两次 | **中止整批导入，回滚事务** |
| ChannelTask 找不到对应 ProductionTask | CT 记录的 PT ID 不在映射表中 | **中止整批导入，回滚事务** |
| 同一 ContentItem 无法正确归并为唯一共享 PT | 旧双 PT 合并规则无法执行（如出现 3 个 PT） | **中止整批导入，回滚事务** |
| Project / Column / Topic / ContentItem 层级关系不一致 | 比如 Topic 的 column_id 指向了一个不存在的 Column | **中止整批导入，回滚事务** |

### 非结构性缺失（允许 warning 后继续）

| 错误类型 | 示例 | 处理 |
| --- | --- | --- |
| 可选历史字段缺失 | 某个栏目没有 historical_closing_line | 记录 warning，继续导入 |
| 图稿资产路径不存在 | artifact_path 指向的文件不存在 | 记录 warning，继续导入 |
| 视频细节字段缺失 | 没有配音时码 | 记录 warning，继续导入 |

> 区分标准：该缺失是否影响**关系完整性**（外键、层级、主键映射）。影响关系完整性 = 结构性错误 = 回滚；不影响 = warning 后继续。

---

## 七、边界与约束

- ❌ 不创建 Seeder / Importer / Migration / Model
- ❌ 不给正式表增加 legacy_id 字段
- ❌ 不把字符串 ID 写入 bigint 字段
- ❌ 不修改原始 DEV-D01 Fixture
- ✅ 历史字符串 ID 继续作为 Fixture 内部引用键
- ✅ 正式 bigint ID 由数据库自增生成
- ✅ 导入期通过内存映射表解析外键
- ✅ 结构性错误触发整批事务回滚，不允许半套数据
