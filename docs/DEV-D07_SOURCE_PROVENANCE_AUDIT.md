# DEV-D07｜Source Provenance 实源审计

> 分支：`doubao/DEV-D07-provenance-baseline-sync`
> 审计日期：2026-10-02
> 任务性质：历史来源文件实源审计 + 正式架构文档基线同步
> 本文件 = 审计结论与设计建议，不是数据库 Migration，也不是新的内容权威源

---

## 1. 实际读取文件清单

本轮实际打开并读取内容的历史来源文件：

| # | 文件 | 路径（相对品牌源根目录） | verification_status |
| --- | --- | --- | --- |
| 1 | 生活小能力 最终上图文案 | `01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md` | READ_AND_VERIFIED（D06 已读） |
| 2 | 看见小情绪 最终上图文案 | `01_2.5D家庭IP形象/看见小情绪/图文/最终上图文案.md` | READ_AND_VERIFIED（D06 已读） |
| 3 | 相处小智慧 最终上图文案 | `01_2.5D家庭IP形象/相处小智慧/图文/最终上图文案.md` | READ_AND_VERIFIED（D06 已读） |
| 4 | 原来在长大 最终上图文案 | `01_2.5D家庭IP形象/原来在长大/图文/最终上图文案.md` | READ_AND_VERIFIED（D06 已读） |
| 5 | 孩子出门总磨蹭 逐页内容脚本 | `2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/孩子出门总磨蹭_十页补全_待确认/逐页内容脚本_待用户确认.md` | READ_AND_VERIFIED（本轮新读） |
| 6 | 六栏目内容台账 | `00_总入口与归档索引/六栏目内容台账.md` | READ_AND_VERIFIED（本轮新读） |
| 7 | 2.5D 栏目收尾文案台账 | `00_总入口与归档索引/2.5D栏目收尾文案台账.md` | READ_AND_VERIFIED（本轮新读） |
| 8 | 2.5D 逐篇最终上图文案入口 | `00_总入口与归档索引/2.5D逐篇最终上图文案.md` | READ_AND_VERIFIED（本轮新读） |

品牌源根目录：`E:\CodexData\CODEX 项目文件夹\QN 青柠育见 品牌\`

---

## 2. 每个 Source Role 的真实审计结论

### 2.1 final_image_copy

| 项目 | 结论 |
| --- | --- |
| **role** | `final_image_copy` |
| **实际文件** | 每栏目一个：`01_2.5D家庭IP形象/<栏目>/图文/最终上图文案.md` |
| **文件数量** | 6 个栏目各一个文件（其中 4 个已收录内容，2 个暂无） |
| **scope** | **ContentItem 级**（一篇内容的所有页面都在同一个文件中） |
| **是否页面级** | ❌ 否。所有页面共享同一篇 Markdown 文件，按页号分段 |
| **是否同一路径被整篇复用** | ✅ 是。同一篇的所有 10/9/10/8 页共享同一个 `final_image_copy` 路径 |
| **authority 推荐** | **authoritative**（逐页正式上图文案的唯一权威源） |
| **verification_status** | READ_AND_VERIFIED |
| **内容特征** | 包含每页的封面标题、副标题、页标题、页小字、收尾句；明确标注了换行符（`／`） |

**审计说明**：
- 该文件是用户确认后的最终上图文字，是 ContentPageVersion 各字段的直接权威来源。
- 发生文案冲突时，此文件优先于任何 Fixture、脚本或映射文档。
- 不包含口播台词、画面描述、场景证据等创作过程内容（那些在 source_script 中）。

---

### 2.2 source_script

| 项目 | 结论 |
| --- | --- |
| **role** | `source_script` |
| **实际文件** | 每篇一个独立的逐页脚本文件，路径因篇目而异，位于 `2.5D家庭IP形象/04_内容与封面模板/图文轮播标准/` 下不同子目录 |
| **文件数量** | 每篇 1 个主脚本文件（部分篇目有多个历史版本） |
| **scope** | **ContentItem 级**（一篇一个文件） |
| **是否页面级** | ❌ 否。所有页面在同一文件中按页号分段 |
| **是否同一路径被整篇复用** | ✅ 是 |
| **authority 推荐** | **evidence**（创作过程证据与口播依据，不是最终上图文案的直接权威） |
| **verification_status** | READ_AND_VERIFIED（已读取《孩子出门总磨蹭》逐页脚本确认内容结构） |
| **内容特征** | 包含每页的职责说明、最终上图文字（与 final_image_copy 对应）、场景证据、人物交流/口播台词、口播指导 |

**审计说明**：
- 逐页脚本是创作过程的完整记录，包含口播台词、画面描述、确认依据。
- 文件名常含"待确认"字样，但实际内容可能已经用户确认（如《孩子出门总磨蹭》已注明"用户于 2026-09-27 确认"）。
- 最终上图文案字段与 `最终上图文案.md` 一致，但脚本还包含大量非上图内容（口播、场景、自审记录）。
- 因此 authority 为 evidence：它是内容创作的证据和口播来源，但最终上图文案的权威源仍是 `最终上图文案.md`。
- 不同篇目的脚本文件名和路径结构差异较大，不适合统一路径规则。

---

### 2.3 content_ledger

| 项目 | 结论 |
| --- | --- |
| **role** | `content_ledger` |
| **实际文件** | `00_总入口与归档索引/六栏目内容台账.md`（整个 Project 一个文件） |
| **文件数量** | 1 个 |
| **scope** | **Project 级**（跨所有栏目，登记每一篇内容的索引） |
| **是否页面级** | ❌ 否 |
| **是否需要 content_column_id** | ✅ 是（台账按主栏目分类，ContentColumn 是其组织维度） |
| **authority 推荐** | **index**（内容索引、状态台账、脚本路径导航） |
| **verification_status** | READ_AND_VERIFIED |
| **内容特征** | 表格形式，每行一篇内容：日期、主栏目、主题、形式、已核实状态、对话/任务、逐页脚本正式路径、素材/交付位置、复核备注 |

**审计说明**：
- 这是 Project 级别的内容总台账，承担"索引 + 状态记录 + 脚本路径导航"三重角色。
- 不包含逐页正文，仅记录每篇的元信息和状态摘要。
- 状态描述（如"十页逐页内容已确认；视频待验收"）是人工备注，不是正式数据库状态的来源。
- 逐页脚本路径列指向 source_script 文件，是脚本定位的索引入口。

---

### 2.4 closing_line_registry

| 项目 | 结论 |
| --- | --- |
| **role** | `closing_line_registry` |
| **实际文件** | `00_总入口与归档索引/2.5D栏目收尾文案台账.md`（整个 Project 一个文件） |
| **文件数量** | 1 个 |
| **scope** | **Project 级**（跨所有栏目，登记每篇收尾句 + 历史栏目句查重参考） |
| **是否页面级** | ❌ 否 |
| **是否需要 content_column_id** | ✅ 是（按主栏目分类登记） |
| **authority 推荐** | **registry**（收尾句查重台账，辅助 Registry，不是内容权威） |
| **verification_status** | READ_AND_VERIFIED |
| **内容特征** | 单篇文案登记表（每篇一行：主栏目、主题、两行完整文案、来源及确认状态、收尾图/视频状态、查重备注）+ 六张历史栏目图查重参考表 |

**审计说明**：
- 这是收尾句的专用台账，核心用途是跨栏目查重——新篇写收尾句时先查此表全部栏目的完整句与近义句。
- 收尾句文案本身来自各篇的 `最终上图文案.md`，此台账是登记和查重工具，不是权威源。
- 包含 6 个历史栏目的固定收尾句参考（如"你肯听，情绪就有了出口"），这些是历史已存在用例，不能直接被新篇复用。
- 台账中的状态（如"视频待验收"）是人工备注，不是正式数据库状态。

---

### 2.5 navigation_index

| 项目 | 结论 |
| --- | --- |
| **role** | `navigation_index` |
| **实际文件** | `00_总入口与归档索引/2.5D逐篇最终上图文案.md`（整个 Project 一个文件） |
| **文件数量** | 1 个 |
| **scope** | **Project 级**（纯导航，指向各栏目的最终上图文案文件） |
| **是否页面级** | ❌ 否 |
| **是否需要 content_column_id** | ✅ 是（按栏目组织导航入口） |
| **authority 推荐** | **navigation**（纯导航索引，不含正文内容） |
| **verification_status** | READ_AND_VERIFIED |
| **内容特征** | 表格形式：主栏目 → 栏目专属文案文件路径 → 目前收录篇目列表 |

**审计说明**：
- 文件开头明确声明："本文件仅作导航，不再保存跨栏目全文副本。"
- 不包含任何完整正文，仅列出各栏目 `最终上图文案.md` 的路径和收录篇目名称。
- 绝对不能当作内容权威源——发生文案冲突时，以各栏目 `最终上图文案.md` 为准。
- 作用仅是帮助快速定位到正确的权威文件。

---

## 3. Scope 汇总表

| role | scope 级别 | 是否页面级 | 是否共享路径 | 是否需要 content_column_id |
| --- | --- | --- | --- | --- |
| `final_image_copy` | ContentItem | ❌ | ✅ 整篇共享 | ❌ |
| `source_script` | ContentItem | ❌ | ✅ 整篇共享 | ❌ |
| `content_ledger` | Project | ❌ | ❌ 全项目一个文件 | ✅ |
| `closing_line_registry` | Project | ❌ | ❌ 全项目一个文件 | ✅ |
| `navigation_index` | Project | ❌ | ❌ 全项目一个文件 | ✅ |

---

## 4. 是否需要 content_page_id

**结论：Lite V1.0 当前不需要 `content_page_id`。**

理由：
- 所有正式来源文件（final_image_copy / source_script）都是 ContentItem 级别的——整篇所有页面在同一个文件中，按页号分段。
- 没有出现"第 1 页一个独立文件、第 2 页一个独立文件"这种页面级独立来源结构。
- 因此 SourceReference 只需关联到 ContentItem，页面定位通过文件内的页号分段实现。

**注意**：这是基于当前 4 篇 / 37 页的实际观察，不是永久禁止。未来如果出现页面级独立来源文件（如某页单独的修订稿），应再评估是否需要 `content_page_id`。

---

## 5. 推荐最小 SourceReference Schema 建议（仅设计建议，不建 Migration）

基于真实审计，建议最小字段集：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | bigint, PK | |
| `project_id` | foreignId, restrictOnDelete | 归属 Project |
| `content_item_id` | unsignedBigInteger, nullable | 关联 ContentItem（ContentItem 级来源时非空） |
| `content_column_id` | unsignedBigInteger, nullable | 关联 ContentColumn（Project 级台账类来源时用于分类） |
| `role` | string(40) | `final_image_copy` / `source_script` / `content_ledger` / `closing_line_registry` / `navigation_index` |
| `authority` | string(40) | `authoritative` / `evidence` / `index` / `registry` / `navigation` |
| `source_path` | string | 文件相对路径 |
| `note` | text, nullable | 备注 |
| timestamps | | |

**约束建议**：
- 复合外键：`(project_id, content_item_id)` → content_items，restrictOnDelete（当 content_item_id 非空时）
- 复合外键：`(project_id, content_column_id)` → content_columns，restrictOnDelete（当 content_column_id 非空时）
- 不使用 polymorphic relation——实际事实证明 role 和 scope 结构清晰，不需要泛型关联。

**不建议加入的字段（超出 Lite 范围）**：
- ❌ hash / checksum
- ❌ watcher / sync queue
- ❌ cloud object storage
- ❌ FileVersion
- ❌ Local Agent 元数据

---

## 6. Importer 使用建议

### 核心原则

**正式 Importer 应直接读取 `最终上图文案.md` 作为权威源，DEV-D06 mapping 仅用于校验和验收。**

- `最终上图文案.md` = canonical content source（逐页正式上图文案的唯一权威）
- DEV-D06 mapping = audit / acceptance mapping（人工审计后的映射文档，用于校验导入结果）

### 建议流程

1. Importer 读取每篇的 `最终上图文案.md`，按页号分段解析各字段（column_label / cover_title / cover_subtitle / page_title / page_small_text / closing_line）。
2. 将解析结果与 DEV-D06 mapping 文档中的逐页映射表做自动比对，确认字段一致。
3. 比对通过后，正式导入到 content_pages / content_copy_revisions / content_page_versions。
4. DEV-D06 mapping 不被当作第二份"正式数据"导入，仅做校验。

### 状态导入注意事项

- 导入时 `copy_status` 设为 `confirmed`，`revision_no = 1`，`confirmed_at` 设为历史确认日期。
- `artwork_status` / `video_status` / `publish_status` 不随 ContentPage 导入而改变，保持各自独立状态。
- 《积木倒了，孩子哭了》的 `wechat_official publish_status` 保持 `unpublished` + 人工核实标记。

---

## 7. 未解决事项

| # | 事项 | 说明 |
| --- | --- | --- |
| 1 | 《积木倒了》公众号实际发布状态 | 仍为 `unpublished` + MANUAL_VERIFICATION，待人工核实 |
| 2 | 视频版收尾句归属结构 | 《孩子出门总磨蹭》视频版专属收尾句未来归入 `wechat_channels` 渠道扩展结构，待渠道专属文案建模 |
| 3 | 逐页脚本的多版本处理 | 部分篇目有多个历史版本脚本（v3/v4/v5/v6），Importer 时应使用哪个版本需人工确认（台账中标注为"已确认"的版本） |
| 4 | SourceReference 正式建模 | 本轮仅审计和建议，正式建表留待后续独立 DEV Task |
| 5 | 历史 Fixture 与 Markdown 逐字差异 | D06 标记为 COPY_DIFF_NOT_EXHAUSTIVELY_COMPARED，导入前需逐字 diff 或直接以 Markdown 为权威导入 |

---

## 8. 与 DEV-D06 的差异更新

| 项目 | DEV-D06 结论 | DEV-D07 更新 |
| --- | --- | --- |
| source_script verification_status | REFERENCED_NOT_READ | ✅ 升级为 READ_AND_VERIFIED（已读取一篇确认内容结构） |
| content_ledger verification_status | REFERENCED_NOT_READ | ✅ 升级为 READ_AND_VERIFIED |
| closing_line_registry verification_status | REFERENCED_NOT_READ | ✅ 升级为 READ_AND_VERIFIED |
| navigation_index verification_status | REFERENCED_NOT_READ | ✅ 升级为 READ_AND_VERIFIED |
| SourceReference scope 结论 | "Lite V1.0 当前不需要 content_page_id" | ✅ 维持，基于实源审计进一步确认 |
| SourceReference 字段建议 | 未给出 | ✅ 新增最小 Schema 建议（仅设计，不建表） |

---

## 9. 边界与约束

- ❌ 不创建 Migration / Model / Factory / API / Controller / Vue
- ❌ 不创建 SourceReference 正式表
- ❌ 不创建 Importer / Seeder
- ❌ 不创建 JSON canonical fixture
- ❌ 不修改任何历史品牌源文件
- ✅ 本文档为审计结论与设计建议，不是新的内容权威源
- ✅ 发生文案冲突时，最终上图文案.md 优先
- ✅ 所有 source role 的 scope / authority / verification_status 基于实际读取，非推测