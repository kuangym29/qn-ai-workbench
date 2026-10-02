# DEV-008B｜青柠育见正式历史文案导入

## 命令与安全边界

`php artisan qn:import-yujian-history --source-root="<品牌源根目录>"` 默认仅预检，数据库零写入。只有显式增加 `--apply` 才在整批数据库事务中导入。`--source-root` 必填；代码和 `source_path` 均不保存机器绝对路径。请只在已确认的独立测试数据库先执行 `--apply`，再安排正式数据库导入。

预检先验证 11 个文件存在，再直接读取四个栏目中的 `图文/最终上图文案.md`，解析 4 篇 37 页，最后与 `DEV-D06_CONTENT_PAGE_MAPPING.md` 逐页比对页型及正式文字字段。D06 是验收对照，不是正文输入。任何源文件缺失、页数/页型/字段不符、目标数据冲突或数据库异常均以 `IMPORT_ABORT` 停止；写入阶段的异常会回滚整个事务。输出会列出页型统计、D06 比对、文件存在数和写入状态。

## 固定迁移清单

Project 为 `青柠育见`（`qingning-yujian`）。六栏目及顺序为：生活小能力 `life-skills` 1、看见小情绪 `emotions` 2、原来在长大 `growing-up` 3、安心小日常 `daily-peace` 4、相处小智慧 `siblings-social` 5、爸妈在成长 `parent-growth` 6。后两栏暂无线上的正式篇目，仅建立栏目。

四个 Topic 和同名 ContentItem：孩子出门总磨蹭 10 页、积木倒了，孩子哭了 9 页、弟弟想玩车，姐姐还没玩完 10 页、一只纸箱，开了家水果店 8 页。页型合计：cover 4、content 25、column_closing 4、fixed_back_cover 4。清单仅保存身份、日期、文件相对路径、预期页型；37 页正文始终来自真实 Markdown。不存在的文字字段保持 `null`，仅将已确认的画面换行标记 `／` 转成真实换行；中文引号、标点及其他字符不做智能清洗。结构性 D06 note 不写入正式文案。

每篇直接建立一个历史正式 `ContentCopyRevision`（revision_no 1）和对应每页一个正式 `ContentPageVersion`（version_no 1）。不制造草稿版本。历史确认日期分别为 2026-09-27、2026-09-27、2026-09-30、2026-09-29；由于原始资料只有日期，统一规范为当日 `00:00:00 UTC`，这不表示真实确认发生于午夜。四个 ContentItem 的 copy_status 为 confirmed。该受控历史导入不调用使用当前时间的交互式确认服务。

11 条 SourceReference 包含三个 Project 级索引/台账及每篇各一条 final_image_copy 和 source_script；authority 从 `SourceRole::defaultAuthority()` 获取。路径相对于品牌源根目录。来源文件实际内容不被复制进数据库。青柠育见品牌目录仍是历史正文权威源。

## 幂等、冲突和业务边界

身份按 Project slug、Column slug、精确 Topic 标题、精确 ContentItem 标题核验。现有结构不一致、重复匹配、未知草稿或页面数据时中止，不改名、不覆盖、不删除。已存在 Revision 1 时逐页核对正式快照、日期和正文；完全一致则保留，Revision 2 及后续历史也不会修改。SourceReference 按 Project、可空 Item、role、path 识别，已存在则核对 authority；重复或冲突时中止。第二次导入应报告 `ALREADY_IMPORTED`，不会创建 Revision 2 或重复来源。

本任务不创建或修改 ProductionTask、ChannelTask，也不推断 artwork、video、publish 状态。《孩子出门总磨蹭》的共享图文收尾句来自最终上图文案；视频专属“你等的这一会儿，\n是她自己来的底气。”留给后续渠道文案扩展（`CHANNEL_SPECIFIC_COPY`），不写入共享 PageVersion。《积木倒了，孩子哭了》的发布状态继续留待人工核验。
