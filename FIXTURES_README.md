# DEV-D01 历史内容迁移 Fixture 说明文档

> 分支：`doubao/DEV-D01-content-fixtures`
> 生成日期：2026-10-01
> 数据范围：青柠育见 4 篇已确认历史内容，共 37 页

---

## 一、文件清单与数据层级

| 文件 | 层级 | 记录数 | 说明 |
| --- | --- | --- | --- |
| `01_projects.json` | Project | 1 | 项目主数据（青柠育见） |
| `02_columns.json` | Column（小栏目） | 6 | 6 个栏目，其中安心小日常/爸妈在成长无正式文章 |
| `03_topics.json` | Topic（选题） | 4 | 4 个已确认选题 |
| `04_content_items.json` | Content Item（篇目） | 4 | 4 篇已确认内容 |
| `05_content_pages.json` | Content Page（逐页） | 37 | 逐页文案（10+9+10+8） |
| `06_production_tasks.json` | Production Task | 6 | 图文制作任务 + 视频制作任务 |
| `07_channel_tasks.json` | Channel Task | 6 | 公众号/视频号渠道发布任务 |
| `test_samples/test_samples.json` | Test Samples | 8 | 8 类查重与状态机测试样本 |

---

## 二、字段含义

### Content Item（篇目）字段

| 字段 | 含义 |
| --- | --- |
| `copy_confirmed` | 逐页文案是否已获用户确认 |
| `artwork_status` | 图稿制作状态：`not_produced` / `candidates_in_review_not_finalized` / `proof_rendered_pending_final_acceptance` / `final_artwork_confirmed` |
| `video_status` | 视频制作状态：`not_started` / `not_produced` / `executable_pending_acceptance` / `reedit_task_order_pending` |
| `publish_status` | 实际发布状态：`not_published` / `artwork_finalized_platform_publish_unverified` |

> **硬约束**：四个状态互相独立，不得从前一个推断后一个。文案确认 ≠ 图稿定稿 ≠ 视频验收 ≠ 已发布。

### Content Page（逐页）字段

| 字段 | 含义 |
| --- | --- |
| `page_type` | `cover` / `content` / `column_closing` / `fixed_back_cover` |
| `closing_line_version` | 收尾句版本：`image_carousel`（图文版）/ `article_specific`（本篇专属）/ `dynamic_video`（动态视频版） |
| `closing_source` | 收尾句来源：`历史固定栏目句` / `本篇专属` |

### Production Task（制作任务）字段

| 字段 | 含义 |
| --- | --- |
| `type` | `image_carousel`（图文轮播）/ `dynamic_story_video`（动态视频） |
| `closing_line_override` | 若该制作任务的收尾句与图文版不同，在此覆盖 |

### Channel Task（渠道任务）字段

| 字段 | 含义 |
| --- | --- |
| `channel` | `微信公众号` / `微信视频号` |
| `closing_line_used` | 该渠道实际使用的收尾句 |

---

## 三、数据来源

| 数据 | 来源文件 |
| --- | --- |
| 逐页文案 | `01_2.5D家庭IP形象/<栏目>/图文/最终上图文案.md`（4 个栏目各一份） |
| 全局状态 | `00_总入口与归档索引/六栏目内容台账.md` |
| 收尾句对照 | `00_总入口与归档索引/2.5D栏目收尾文案台账.md` |
| 制作过程 | 各篇 `试稿_*` 目录下的任务单、确认卡、验收记录 |
| 双渠道收尾差异 | `孩子出门总磨蹭_十页补全_待确认/双渠道共用底图_流程试跑_2026-09-30.md` |

---

## 四、能够直接确认的状态

| 篇目 | 文案确认 | 图稿状态 | 视频状态 |
| --- | --- | --- | --- |
| 孩子出门总磨蹭（生活小能力） | ✅ 2026-09-27 | 排版样张已做，双渠道验收未过 | 动态包可执行，待验收 |
| 积木倒了，孩子哭了（看见小情绪） | ✅ 2026-09-27 | ✅ 图文发布定稿 2026-09-28 | V7 重剪任务单，待验收 |
| 弟弟想玩车，姐姐还没玩完（相处小智慧） | ✅ 2026-09-30 | 未生图 | 未启动 |
| 一只纸箱，开了家水果店（原来在长大） | ✅ 2026-09-29 | 候选在审，未交付 | 未启动 |

**关键确认点**：
- 《孩子出门总磨蹭》图文版收尾句：「你在身边，"我自己来"更有底气。」
- 《孩子出门总磨蹭》动态视频版收尾句：「你等的这一会儿，是她自己来的底气。」
- 两句独立，禁止合并覆盖。

---

## 五、无法从现有资料判断的状态（标为 unknown / 保守值）

| 项目 | 现状 | 原因 |
| --- | --- | --- |
| 各篇是否已实际发布到公众号/视频号 | `not_published` / `publish_unverified` | 台账明确警告"勿将文件夹名中的定稿误认为成片已正式发布"，无后台发布记录 |
| 《积木倒了》图文定稿后是否已上线 | `artwork_finalized_platform_publish_unverified` | 有定稿文件，但无发布截图或后台记录佐证 |
| 视频版配音、音效、时码细节 | 仅记到"待验收" | 逐字台词以剪辑单为准，未逐文件核对 |
| 《纸箱水果店》生图候选的具体通过/淘汰清单 | `candidates_in_review_not_finalized` | 有候选图和验收记录，但整套交付未完成 |
| 《弟弟想玩车》是否有任何视觉稿 | `not_produced` | 台账明确"未生图"，无正式交付图 |

---

## 六、需要人工复核的事项

1. **《积木倒了》图文发布状态**：文件夹名为"发布定稿"，但台账说"勿误认为成片已正式发布"。需人工确认是否已在公众号/视频号实际发出。
2. **《孩子出门总磨蹭》双渠道 3:4 模板**：目前只有试跑记录，公众号 3:4 正式有字稿未制作。需确认后续模板方向。
3. **《纸箱水果店》图稿进度**：有第 05 页复工验收，但其他页状态不明。需人工核对整套生图进度。
4. **视频版配音与角色音色**：各视频任务仅记到"待验收"，角色音色、台词版本需以剪辑单为准，未纳入 fixture。
5. **安心小日常、爸妈在成长**：仅登记栏目存在，无任何正式文章。不得用历史草稿填充。

---

## 七、严格遵守的边界

- ❌ 未创建"2.5D 图文"独立 Content Profile（历史内容只是资料来源，不是系统内容类型）
- ❌ 未拿历史草稿填充"安心小日常"和"爸妈在成长"
- ❌ 中央导航文件未复制完整正文，仅引用路径
- ✅ 各栏目「最终上图文案.md」作为正式内容来源
- ✅ 状态严格分层：文案 / 图稿 / 视频 / 发布 四者独立
