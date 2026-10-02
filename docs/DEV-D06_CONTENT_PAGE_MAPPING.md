# DEV-D06｜历史 37 页 → ContentPage Schema 精确映射

> 分支：`doubao/DEV-D06-content-page-mapping`
> 生成日期：2026-10-02
> 任务性质：历史数据审计与迁移映射（Derived Migration Mapping）
> 正式 Schema：content_pages / content_copy_revisions / content_page_versions

---

## 0. 权威来源声明（最重要）

**发生冲突时：`最终上图文案.md` 优先。**

- **最终上图文案.md** = 逐页正式文案权威源
- **旧 DEV-D01 Fixture** = 迁移辅助 / legacy ID 辅助（不是内容权威源）
- 本文件 = Derived Migration Mapping（不是新的内容权威源）
- 不修改任何历史 Markdown 文件
- 不制造第二份"权威 JSON"

---

## 1. 权威来源文件清单

| 篇目 | Legacy ContentItem ID | 栏目 | 权威源路径 |
| --- | --- | --- | --- |
| 孩子出门总磨蹭 | CI-LIFE-001 | 生活小能力 | `01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md` |
| 积木倒了，孩子哭了 | CI-EMO-001 | 看见小情绪 | `01_2.5D家庭IP形象/看见小情绪/图文/最终上图文案.md` |
| 弟弟想玩车，姐姐还没玩完 | CI-SOC-001 | 相处小智慧 | `01_2.5D家庭IP形象/相处小智慧/图文/最终上图文案.md` |
| 一只纸箱，开了家水果店 | CI-GROW-001 | 原来在长大 | `01_2.5D家庭IP形象/原来在长大/图文/最终上图文案.md` |

所有路径基于：`E:\CodexData\CODEX 项目文件夹\QN 青柠育见 品牌\`

---

## 2. 四篇总表

| 篇目 | Legacy ID | 栏目 | 页数 | 封面 | 正文 | 栏目收尾 | 固定封底 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 孩子出门总磨蹭 | CI-LIFE-001 | 生活小能力 | 10 | 1 | 7 | 1 | 1 |
| 积木倒了，孩子哭了 | CI-EMO-001 | 看见小情绪 | 9 | 1 | 6 | 1 | 1 |
| 弟弟想玩车，姐姐还没玩完 | CI-SOC-001 | 相处小智慧 | 10 | 1 | 7 | 1 | 1 |
| 一只纸箱，开了家水果店 | CI-GROW-001 | 原来在长大 | 8 | 1 | 5 | 1 | 1 |
| **合计** | | | **37** | **4** | **25** | **4** | **4** |

页数校验：10 + 9 + 10 + 8 = 37 ✅

---

## 3. 换行规则（raw → normalized）

### 规则
- 历史 Markdown 中使用「／」（全角斜杠）表示画面内换行，不印在画面上
- 数据库 `page_title` / `page_small_text` / `cover_title` / `cover_subtitle` / `closing_line` 中应存为实际换行符 `\n`
- 不擅自"美化"原文，不调整标点，不润色字句

### 示例
| Raw source | Normalized database value |
| --- | --- |
| `孩子出门／总磨蹭／先别急着催` | `孩子出门\n总磨蹭\n先别急着催` |
| `少说："快一点！"／试试："先把这只鞋拿起来。"` | `少说："快一点！"\n试试："先把这只鞋拿起来。"` |

---

## 4. PageType 分布

| PageType | 数量 | 说明 |
| --- | --- | --- |
| `cover` | 4 | 每篇第 1 页 |
| `content` | 25 | 普通正文页 |
| `column_closing` | 4 | 每篇栏目收尾页 |
| `fixed_back_cover` | 4 | 每篇固定封底页 |
| **合计** | **37** | |

> 无不确定的 PageType，全部逐页核对确认。

---

## 5. nullable 封底

每篇最后一页（fixed_back_cover）无本篇新增文案，正式字段全部为 null：

| 篇目 | 封底页码 | note |
| --- | --- | --- |
| 孩子出门总磨蹭 | 第 10 页 | 使用既有固定封底模板 |
| 积木倒了，孩子哭了 | 第 9 页 | 使用既有固定封底模板 |
| 弟弟想玩车，姐姐还没玩完 | 第 10 页 | 使用既有固定封底模板 |
| 一只纸箱，开了家水果店 | 第 8 页 | 使用既有固定封底模板 |

---

## 6. 逐页映射（37 页）

---

### 篇 1：《孩子出门总磨蹭》CI-LIFE-001（10 页）

> source_role = `final_image_copy`
> source_path = `01_2.5D家庭IP形象/生活小能力/图文/最终上图文案.md`

| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | cover | 生活小能力 | 孩子出门\n总磨蹭\n先别急着催 | 把催促换成孩子听得懂的一小步 | null | null | null | null |
| 2 | content | null | null | null | 先看孩子\n卡在哪一步 | 先观察孩子停在哪儿，不急着催。 | null | null |
| 3 | content | null | null | null | 把催促换成\n具体一步 | 少说："快一点！"\n试试："先把这只鞋拿起来。" | null | null |
| 4 | content | null | null | null | 说完，\n停一停 | 给孩子一点时间，自己拿起鞋。 | null | null |
| 5 | content | null | null | null | 完成一步，\n再说下一步 | 第一只穿好，再准备另一只。 | null | null |
| 6 | content | null | null | null | 第二只鞋\n也穿好啦 | "妈妈，我穿好啦！" | null | null |
| 7 | content | null | null | null | 看见这一小步\n具体夸一夸 | "我看到你把第二只鞋也穿好了，真棒！" | null | null |
| 8 | content | null | null | null | 准备好了，\n一起出门！ | 穿好鞋，和妈妈高高兴兴走出家门。 | null | null |
| 9 | column_closing | null | null | null | null | null | 你在身边，\n"我自己来"更有底气。 | 图文版共享收尾句 |
| 10 | fixed_back_cover | null | null | null | null | null | null | 使用既有固定封底模板 |

**特殊收尾句说明**：
- 图文版共享收尾句（进入 `ContentPageVersion.closing_line`）：`你在身边，\n"我自己来"更有底气。`
- 视频版专属收尾句（**不进入共享 ContentPageVersion**，标记 `CHANNEL_SPECIFIC_COPY`）：`你等的这一会儿，\n是她自己来的底气。`
- 未来归属：`wechat_channels` 渠道专属文案 / script override
- 禁止覆盖图文版本

---

### 篇 2：《积木倒了，孩子哭了》CI-EMO-001（9 页）

> source_role = `final_image_copy`
> source_path = `01_2.5D家庭IP形象/看见小情绪/图文/最终上图文案.md`

| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | cover | 看见小情绪 | 积木倒了\n孩子哭了\n先别急着搭 | 他搭了好久的\n小房子，散了一地。 | null | null | null | null |
| 2 | content | null | null | null | 妈妈先蹲下\n没去扶积木 | 确认没有受伤，她先把注意力放在孩子身上。 | null | null |
| 3 | content | null | null | null | 他握起屋顶\n小声说"我的……" | 妈妈没有接过积木，等他把话说完。 | null | null |
| 4 | content | null | null | null | "你搭了好久，\n倒了很难过吧？" | 先接住他的失落，不急着讲办法。 | null | null |
| 5 | content | null | null | null | "想先歇一会儿，\n还是一起再搭？" | 等他能听进去，妈妈才把选择交给他。 | null | null |
| 6 | content | null | null | null | 他自己挑一块\n轻轻放稳了 | 妈妈说："你自己挑的这块，放得很稳。" | null | null |
| 7 | content | null | null | null | 屋顶重新放好\n他笑着看妈妈 | 这一次，是他自己选了"再试试"。 | null | null |
| 8 | column_closing | null | null | null | null | null | 你肯听，情绪就有了出口。 | null |
| 9 | fixed_back_cover | null | null | null | null | null | null | 使用既有固定封底模板 |

**发布状态注意**：
- `wechat_official publish_status = unpublished`
- `MANUAL_VERIFICATION` 标记
- 不因文案 confirmed / 图稿 approved 推断 published

---

### 篇 3：《弟弟想玩车，姐姐还没玩完》CI-SOC-001（10 页）

> source_role = `final_image_copy`
> source_path = `01_2.5D家庭IP形象/相处小智慧/图文/最终上图文案.md`

| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | cover | 相处小智慧 | 弟弟想玩车\n姐姐还没玩完\n先别催她让 | 一辆小车，两个孩子；\n先别急着判谁该让。 | null | null | null | null |
| 2 | content | null | null | null | 弟弟伸手拿\n姐姐抱紧车 | 争抢刚起，妈妈先走近两个孩子。 | null | null |
| 3 | content | null | null | null | 妈妈蹲下来\n"先不抢。" | 先挡住拿车的动作，不从姐姐手里夺车。 | null | null |
| 4 | content | null | null | null | 妈妈先听见\n两个人的需要 | 姐姐还在玩，弟弟也想玩。怎么办？ | null | null |
| 5 | content | null | null | null | "再开一圈，\n就给弟弟玩。" | 姐姐说出顺序，妈妈再让弟弟知道。 | null | null |
| 6 | content | null | null | null | 弟弟等得急\n妈妈陪他等 | "你很想玩呀，我陪你等她开完。" | null | null |
| 7 | content | null | null | null | 姐姐玩好了\n把车递给弟弟 | 姐姐的这一圈没被打断；弟弟也等到了。 | null | null |
| 8 | content | null | null | null | 弟弟开着车\n姐姐在旁指路 | 愿意时一起玩，不想一起也不用勉强。 | null | null |
| 9 | column_closing | null | null | null | null | null | 轮流不是催姐姐让，\n而是让两个孩子都被听见。 | 本篇专属栏目收尾 |
| 10 | fixed_back_cover | null | null | null | null | null | null | 使用既有固定封底模板 |

---

### 篇 4：《一只纸箱，开了家水果店》CI-GROW-001（8 页）

> source_role = `final_image_copy`
> source_path = `01_2.5D家庭IP形象/原来在长大/图文/最终上图文案.md`

| page_no | page_type | column_label | cover_title | cover_subtitle | page_title | page_small_text | closing_line | note |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | cover | 原来在长大 | 一只纸箱\n开了家水果店 | 孩子在玩什么？先听听他的设定。 | null | null | null | null |
| 2 | content | null | null | null | 纸箱成了小店\n他在玩假装游戏 | 他让纸箱当小店，自己当卖水果的人。 | null | null |
| 3 | content | null | null | null | 他招呼爸爸\n"来买苹果吧" | 游戏由孩子发起，爸爸先听他的安排。 | null | null |
| 4 | content | null | null | null | 爸爸坐下来\n当第一位顾客 | "你好，我想买一个苹果。" | null | null |
| 5 | content | null | null | null | 他挑出苹果\n递到爸爸面前 | "给你。"爸爸回应："谢谢你，店长。" | null | null |
| 6 | content | null | null | null | 爸爸拿着苹果\n弟弟挥手说再见 | 孩子邀请时，先听设定，再接一句。 | null | null |
| 7 | column_closing | null | null | null | null | null | 他把纸箱当成小店，\n你认真走进他的想象。 | 本篇专属栏目收尾 |
| 8 | fixed_back_cover | null | null | null | null | null | null | 使用既有固定封底模板 |

---

## 7. 特殊收尾句汇总

| 篇目 | 图文版共享收尾句 | 视频版专属收尾句 | 标记 |
| --- | --- | --- | --- |
| 孩子出门总磨蹭 | 你在身边，\n"我自己来"更有底气。 | 你等的这一会儿，\n是她自己来的底气。 | CHANNEL_SPECIFIC_COPY（视频版不进共享） |
| 积木倒了，孩子哭了 | 你肯听，情绪就有了出口。 | null | 无视频版专属差异 |
| 弟弟想玩车，姐姐还没玩完 | 轮流不是催姐姐让，\n而是让两个孩子都被听见。 | null | 无视频任务 |
| 一只纸箱，开了家水果店 | 他把纸箱当成小店，\n你认真走进他的想象。 | null | 无视频任务 |

---

## 8. Manual Review 项

| # | 项目 | 说明 |
| --- | --- | --- |
| 1 | 《积木倒了》公众号发布状态 | 保持 `unpublished` + `MANUAL_VERIFICATION`，待人工核实 |
| 2 | 视频版收尾句归属 | 孩子出门总磨蹭的视频版收尾句未来归入 `wechat_channels` 渠道专属文案，待渠道扩展结构建模 |

> 无 PageType 不确定项，无字段缺失需人工补录项。

---

## 9. 与旧 Fixture 的差异

| 项目 | 旧 Fixture | 权威 Markdown | 处理 |
| --- | --- | --- | --- |
| 逐页文案内容 | 旧 Fixture 有逐页字段 | 以 Markdown 为准 | 冲突时 Markdown 优先 |
| 换行表示 | 旧 Fixture 用 `/` 或 `\n` | 权威源用 `／`（全角斜杠） | 统一转换为 `\n` 入库 |
| 收尾句 | 旧 Fixture 有 closing_line_source 字段 | 以 Markdown 中实际内容为准 | 以 Markdown 为准 |
| 页数 | 旧 Fixture 记录 10/9/10/8 | 实际核对一致 | 无差异 |

> 本次审计未发现 Fixture 与权威 Markdown 在页数、篇名、栏目归属上的不一致。文案细节以 Markdown 为最终权威。

---

## 10. 与旧 D05 的差异

| 项目 | 旧 D05 结论 | 新 D06 结论 |
| --- | --- | --- |
| ContentPage 导入状态 | `BLOCKED_BY_CONTENT_PAGE` | ✅ **已解除** — ContentPage Schema 已正式建立（content_pages / content_copy_revisions / content_page_versions） |
| 37 页文案 | 标记为待建模 | 已逐页精确映射，字段完整 |
| 数据源定位 | "Fixture 是唯一历史正式数据源" | ❌ 已纠正 — 最终上图文案.md = 逐页正式文案权威源；Fixture = 迁移辅助 |

---

## 11. 新 Schema 导入准备度

| 正式表 | 准备状态 | 说明 |
| --- | --- | --- |
| content_pages | ✅ READY | 37 页 page_no / page_type 已明确 |
| content_copy_revisions | ✅ READY | 每篇 1 条，revision_no = 1，copy_status = confirmed |
| content_page_versions | ✅ READY | 37 页完整快照，copy_revision_id = Revision 1 |

### 导入时不变的状态（DEV-04 已确认）
- `artwork_status`：不因 ContentPage 导入而改变
- `video_status`：不因 ContentPage 导入而改变
- `publish_status`：不因 ContentPage 导入而改变

---

## 12. Source Reference 观察

> 本轮不建 SourceReference 表，仅记录真实观察，供后续 DEV-006C 参考。

### 12.1 实际存在的 source role 观察

| role | 实际情况 | 是否需要 |
| --- | --- | --- |
| `final_image_copy` | 4 篇各 1 个 Markdown 文件，整篇复用 | ✅ 需要，篇目级引用 |
| `source_script` | 每篇 Markdown 头部引用了逐页脚本路径（如 `孩子出门总磨蹭_十页补全_待确认/逐页内容脚本_待用户确认.md`） | 可能需要，篇目级 |
| `content_ledger` | 栏目收尾句另同步到 `2.5D栏目收尾文案台账.md` | 可能需要，栏目级 |
| `closing_line_registry` | 栏目收尾文案台账 | 可能需要，栏目级 |
| `navigation_index` | `00_总入口与归档索引` | 可能需要，项目级 |

### 12.2 粒度观察

| 问题 | 观察结论 |
| --- | --- |
| 哪些 source ref 是篇目级？ | `final_image_copy` 和 `source_script` 都是篇目级（一篇一个文件） |
| 哪些是页面级？ | 当前无页面级独立 source 文件，所有页面都在同一篇 Markdown 中 |
| 是否同一路径被整篇复用？ | ✅ 是 — 同一篇的所有页面共享同一个 `final_image_copy` 路径 |
| 是否真的需要 content_page_id？ | 当前看，篇目级引用足够；页面级 source ref 暂不需要独立 content_page_id 关联 |
| 哪些 role 实际存在？ | 实际确认存在：`final_image_copy`（4 篇各 1）。其余 role 为文档中引用但未直接读取，需后续确认 |

---

## 13. 边界与约束

- ❌ 不创建 Seeder / Importer / Migration / Model / Controller / Vue
- ❌ 不创建 JSON fixture 正式副本
- ❌ 不修改历史 Markdown 文件
- ❌ 不修改旧 DEV-D01 Fixture
- ✅ 本文档为 Derived Migration Mapping，不是新的内容权威源
- ✅ 发生冲突时最终上图文案.md 优先
- ✅ 37 页逐页映射完整
- ✅ 换行规则明确（／→\n）
- ✅ 特殊收尾句独立标记，不覆盖
