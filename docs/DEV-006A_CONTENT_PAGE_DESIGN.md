# DEV-006A｜ContentPage 与文案版本设计审核稿

> 状态：待架构审核。本文只确定数据边界与 DEV-006B 的实现建议，不代表 Migration、Model、API 或导入器已经建立。正式基线：`main` 的 `57c0def0f72e61d1b5ed5da6b5db0d5654a5fe25`。

## 1. 业务需求与当前约束

业务层级仍为 `Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask`；逐页内容是 ContentItem 的子能力，不增加 Content Profile。历史有四篇、共 37 页，页数为 10、9、10、8。逐页需要封面、正文、栏目收尾、固定封底、文案查重和后续视觉规划。正式确认的文案不能被下一次编辑覆盖；文案确认也不代表图稿、视频或发布验收。

现有 `ContentItem.copy_status` 的合法值为 `not_started`、`editing`、`pending_confirmation`、`confirmed`。Project 是最高隔离边界；服务端须先校验当前 Session Project，再通过 ContentItem 关系读取页面。历史权威来源是各栏目 `01_2.5D家庭IP形象\<栏目>\图文\最终上图文案.md`，旧 Fixture 仅用于核对映射，不能反过来取代原文。

## 2. 三种候选模型

| 方案 | 结构 | 优点 | 关键缺点 |
| --- | --- | --- | --- |
| A：页面直接存文案 | `content_pages` 同时存页身份、顺序、类型和文字 | 最少表，当前页查询直接 | 更新会覆盖已确认正文；补审计表又回到版本模型；历史正式稿难以可靠重建 |
| B：稳定页面 + 文案版本 | `content_pages` 存页身份与当前编排；`content_page_versions` 存逐页文字及确认快照 | 页面 ID 稳定、正式文本可追加保留、显式字段便于查重；无需通用 CMS | 确认整篇时须用事务写完整快照；会重复存储未改动页的文字 |
| C：整篇 JSON 快照 | ContentItem 下面保存每次整篇页面数组的 JSON 文案快照 | 整篇历史容易保存；表数可少 | 单页身份、逐字段查重与单页引用都依赖 JSON 解析；跨页和资产关联不稳，历史导入校验困难 |

**推荐 B。** 四篇 37 页的规模下，确认时复制未改动页面的少量文字，比引入通用块系统或不可靠的历史重建更可控。A 无法单靠状态字段保证不覆盖；C 把稳定页 ID 和检索约束藏进 payload。

## 3. 文案字段方案比较

| 维度 | 显式 nullable 列（推荐） | JSON copy payload | PageTextBlock 子表 |
| --- | --- | --- | --- |
| Lite V1.0 简洁度 | 六个明确字段，最少规则 | 表面列少，读写需解析 | 每个字段多行与角色约束 |
| 查询与逐字段查重 | 可直接选择具体列、建立后续索引 | MySQL/SQLite JSON 查询差异，字段名不易约束 | 按角色 join/聚合，查询复杂 |
| 版本管理 | 一行即一页文案快照 | 一行 JSON 快照，但字段演进需兼容解析 | 一个版本拆为多行，确认事务更复杂 |
| 历史导入 | 旧 `content_title`、`small_text` 可明确映射 | 易吞下不一致数据 | 需先统一角色字典 |
| 扩展与维护 | 新的稳定字段以后独立迁移 | 灵活但 schema 不显式 | 很灵活，维护成本最高 |

推荐在 `content_page_versions` 明确使用 `column_label`、`cover_title`、`cover_subtitle`、`page_title`、`page_small_text`、`closing_line` 六个 nullable 文字列；`note` 仅作内部备注，不进入默认查重正文。不是每种 Page Type 都要求六列非空；具体展示必填规则在服务端按类型校验，不以数据库 ENUM 固化。

## 4. 推荐表结构草案

### `content_pages`：稳定页面身份与当前编排

| 字段 | 意义 |
| --- | --- |
| `id` | 稳定页面主键；重排不变 |
| `project_id` | 明确隔离边界 |
| `content_item_id` | 所属篇目；不另存 `content_column_id`、`topic_id` |
| `page_no` | 当前页序，正整数；同篇唯一，最终展示建议连续 1…N |
| `page_type` | 当前类型，受 PHP 字符串 Enum 控制，数据库为 string |
| `created_at`, `updated_at` | 页面身份与编排时间 |

约束：`(project_id, content_item_id)` 复合外键引用现有 `content_items(project_id, id)`；`(content_item_id, page_no)` 唯一；为版本表提供 `(project_id, id)` 唯一键。Project/Column/Topic 从 ContentItem 关系推导，不在页表重复。创建、重排须经已校验的 ContentItem；跨 Project 返回 404。

### `content_page_versions`：逐页文案记录

| 字段 | 意义 |
| --- | --- |
| `id`, `project_id`, `content_page_id` | 版本主键、Project 与稳定页归属 |
| `version_no` | 该页从 1 递增的版本号；每次保存新文字插入新行 |
| `copy_revision_no` nullable | 整篇第 N 次正式确认的快照号；草稿为 null |
| `page_no_snapshot`, `page_type_snapshot` | 正式确认时的页序和类型；保存当时编排 |
| `column_label`, `cover_title`, `cover_subtitle`, `page_title`, `page_small_text`, `closing_line`, `note` | 显式可空文案/备注字段 |
| `confirmed_at` nullable | 该行是否属于正式确认快照；与 `copy_revision_no` 同时有值或同时为 null |
| `created_at` | 创建时间；正式文本后续不可更新 |

约束：`(project_id, content_page_id)` 复合外键引用 `content_pages(project_id, id)`；`(content_page_id, version_no)` 唯一；`(content_page_id, copy_revision_no)` 唯一（草稿 null 可重复）。版本表不重复 `content_item_id`；经 ContentPage 归属篇目。追加版本必须由服务端按当前 Project、ContentItem、Page 校验。`copy_revision_no` 的同一批正式行须覆盖当时所有有效页面；由一次数据库事务与测试保证。

建议在 `content_items` **新增** nullable `confirmed_copy_revision_no`，指向当前已确认的整篇快照号；原 DEV-002 Migration 不改。该整数不是新状态机，只是读取正式快照的指针。DEV-006B 需核对 MySQL 8.4 与 SQLite 的复合外键、唯一索引和 null 语义。不要加入 `is_current`、`superseded_by` 或通用工作流列；最新版由 `version_no` 推导，正式版由 `copy_revision_no` 指定。

## 5. 状态、草稿与不可覆盖机制

`ContentItem.copy_status` 只描述整篇文案的工作状态：未开始、编辑、待确认、已确认。Page Version 只记文本历史及其是否属于第 N 次正式确认，不复制四值状态机。编辑一页时追加一条 `copy_revision_no = null` 的版本；待确认仍通过 ContentItem 状态表达。版本按 `version_no` 递增，最新草稿取当前正式快照之后的最新未确认行；没有新草稿时展示当前正式行。

确认整篇时，在一个事务中锁定 ContentItem，检查页序、类型与必填文案，按每页最新文字生成**完整**的第 N 次正式快照（未改动页也复制一行），设置这些新行的 `copy_revision_no = N` 与 `confirmed_at`，再更新 `content_items.confirmed_copy_revision_no = N`、`copy_status = confirmed`。只有事务全部成功才对外显示新正式稿。此后任何文本修改只追加草稿行，并将 ContentItem 回到 `editing`；不能 UPDATE 已有正式版本的文字、页序快照、类型快照或确认标记。服务端只提供插入草稿/确认快照的写路径，测试覆盖“已确认行更新被拒绝”；直连数据库管理员改写不属于应用权限保证范围。

重新确认时 N 递增，旧批次完整保留。历史正式稿按 `(ContentItem, copy_revision_no)` 读取该批所有页快照，按 `page_no_snapshot` 排序。`copy_status = confirmed` 只表示当前工作稿已确认；不用于反推图稿、视频或发布状态。并发确认需锁定 ContentItem，避免同一 N 重复。无全文确认事件表或复杂审核流。

## 6. 页面顺序、增删与类型

同一 ContentItem 的当前 `page_no` 唯一。新页取明确页号；重排在事务中使用临时空号/两阶段更新，最终校验连续 1…N，不引入分数排序。稳定 `content_pages.id` 不因重排而改变。正式快照同时保存 `page_no_snapshot`，因此后续重排不会改变旧正式版本。Lite V1.0 可允许当前草稿增页与重排；确认时生成全篇快照。已确认页面不得硬删除。删除页的产品语义与引用影响留待后续独立任务；若以后支持移除，应保留页面身份并仅在新整篇快照中不包含它，旧快照仍可读取。DEV-006B 不开放删除 API。

`page_type` 推荐 PHP string backed Enum（数据库 string）：`cover`、`content`、`column_closing`、`fixed_back_cover`。含义分别为封面、普通正文、栏目/系列收尾、固定封底；类型可跨 Project 复用，不包含品牌、画风或人物设定。固定封底可能没有本篇新增文字，不应为凑字段制造空白文案；其模板来源用 Source Reference 记录。历史 `cover/content/column_closing/fixed_back_cover` 可原样映射。

## 7. 查重字段与历史映射

| 正式查重角色 | 推荐列 | 历史字段 |
| --- | --- | --- |
| 封面标题 | `cover_title` | `cover_title` |
| 封面副标题 | `cover_subtitle` | `cover_subtitle` |
| 页面标题 | `page_title` | `content_title` |
| 页面小字 | `page_small_text` | `small_text` |
| 收尾句 | `closing_line` | `closing_line` |

`column_label` 为栏目标签，可用于展示，不默认混入逐页正文查重；`note` 为工作备注，也不默认查重。重复检测以后按角色分别归一化和比对，不把全部字段拼成不透明长文本。历史 `／` 表示换行而非上图字符：导入时保留原标点并作明确换行转换，记录来源路径与核对结果；不能仅依 Fixture 推断最终确认文案。封底“调用用户原稿；无本篇新增文案”保持字段 null。

## 8. 收尾句与渠道覆写边界

《孩子出门总磨蹭》第 09 页共享图文确认稿为“你在身边，／“我自己来”更有底气。”（保留原文引号）；动态视频稿为“你等的这一会儿，／是她自己来的底气。”。两者是不同媒介的正式文本，不得把动态视频句写成同一个 `ContentPage.closing_line` 的另一个页面版本，也不得覆盖图文正式版本。共享 ContentPageVersion 记录图文版 `closing_line`；视频专属文案将来由对应 ChannelTask 的 copy override/脚本版本承载，并保留其来源与确认状态。DEV-006A 不定义 ChannelTask override 表，也不把其状态并入 `copy_status`。

## 9. Source Reference 边界

来源文件关系应独立于 `content_pages` 和文字版本字段。建议后续轻量 `source_references`：`project_id`、`content_item_id`、可空 `content_page_id`、受控字符串 `role`、`local_path`、可空 `cloud_attachment_url`、`note`、时间戳。角色先覆盖 `final_image_copy`、`source_script`、`content_ledger`、`closing_line_registry`、`navigation_index`。同一栏目权威 Markdown 可被多篇引用；路径是指向原资料的引用，不复制成第二份权威源。可选云附件只是便利副本。跨 Project/篇目/页的引用须验证关系一致。本轮不建表、不做 FileVersion 或自动同步；是否在 DEV-006B 建此表需单独确认。

## 10. 37 页历史导入兼容性

| 篇目 | 页数 | 映射重点 |
| --- | ---: | --- |
| 《孩子出门总磨蹭》 | 10 | 第 09 页图文收尾句保留原文；动态视频句另留渠道边界；第 10 页固定封底无新增文字 |
| 《积木倒了，孩子哭了》 | 9 | v6 九页图文确认稿与视频 V7 重剪任务分离 |
| 《弟弟想玩车，姐姐还没玩完》 | 10 | 十页已确认，但图像未验收 |
| 《一只纸箱，开了家水果店》 | 8 | 八页文字已确认，分镜/生图/交付未验收 |

导入器未来从各栏目 `图文\最终上图文案.md` 读取、逐篇逐页比对页号/类型/字段，先产出可审查差异表，再写数据库；旧 Fixture 只辅助定位与交叉核查。首批每篇建立稳定页身份与第 1 次正式确认快照，ContentItem 指针为 1、`copy_status = confirmed`，但不得因此自动批准 artwork/video/publish。导入需验证总数 37、每篇页数 10/9/10/8、封底空文案及特殊收尾句分离；遇到原文歧义暂停该篇导入，不静默改写历史来源。本文不执行导入。

## 11. 未来视觉资产关联点

页面文字和视觉资产分域。未来 Asset 属于 ProductionTask，并可带 `content_page_id` 定位稳定页、`content_page_version_id` 标记制作时依据的正式文案版本；两者与 Asset 的 Project/ContentItem/ProductionTask 上下文须一致，复合外键或等效服务端约束及测试共同保护。`clean_master` 与 `copy_master` 是资产角色，不是 ContentPage 列；图片和视频文件也不进入页面表。ChannelTask 引用共享资产，渠道适配版独立衍生，不覆盖母资产。

## 12. Lite V1.0 边界、风险与 DEV-006B 建议

本设计不引入 Content Profile、2.5D 独立类型、多租户 SaaS、万能 Block Builder、DAG/低代码、Vector DB、复杂 Workflow/AssetVersion、Windows Local Agent。本轮不实现 Migration、Model、Controller、Request、Resource、Route、Vue 或 Importer。

主要风险：① 若只靠应用服务保护正式行，数据库管理员仍可直接修改，须以最小数据库账号权限、代码路径审查和测试降低风险；② 并发编辑/确认需锁定 ContentItem 并保证 `version_no`/`copy_revision_no` 唯一；③ MySQL 与 SQLite 对复合外键、null 唯一及重排中间态的行为须实测；④ 来源 Markdown 与 Fixture 可能有标点/换行差异，应以权威 Markdown 为准；⑤ `page_type` 的栏目收尾语义可能被其他 Project 用作系列收尾，UI 文案须保持通用；⑥ 尚无用户成员授权，Session Project 作用域不等于用户权限；⑦ Source Reference 与渠道文案覆写的确切表结构尚未审核。

**DEV-006B 建议顺序：** 先评审本稿并确认是否采用完整整篇快照；再用新 Migration 建两表及 ContentItem 的确认快照指针，不编辑历史 Migration；实现 PHP PageType Enum、Model 关系和复合外键测试；以测试先行实现追加草稿、事务确认、禁止正式行覆盖、重排与跨 Project 404；最后核对 MySQL 8.4 与 SQLite。历史导入、渠道覆写、资产关联和前端页面均另立任务，不随 DEV-006B 顺手实现。
