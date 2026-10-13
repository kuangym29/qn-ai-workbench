# V2 Foundation 验收基线

本阶段面向 1–2 人团队，完成灵感、选题、单一内容母体、多渠道分支和进行中闭环。旧 V1 数据可丢弃，不承诺生产数据迁移或回填。下列手工验收已于2026-10-13由Work在独立测试环境完成，记录见V2_P2_A01_BROWSER_ACCEPTANCE.md。

## 当前约束

- 真实来源链为 Inspiration → Topic → ContentItem → ContentBranch；直接创建选题允许没有 Inspiration。SourceReference 不承担业务来源。
- 一个 Topic 最多一个 ContentItem；分支按 content_item_id + channel + format 唯一。停止保留记录，再添加恢复原 ID。
- 只开放公众号小绿书、公众号图文、视频号视频；小红书只预留。
- Topic 只有待选/暂存，备用为独立星标。栏目必选；开始创作后不能更换栏目。标题最多 200 字符。
- ContentItem 只维护共享标题、核心表达、叙事线，不录入重复渠道内容。
- 全部业务接口检查登录与 Session 当前 Project，跨项目对象返回 404。

## 手工验收清单（2026-10-13已执行）

- [x] 登录，创建/选择项目和栏目；快速记灵感，仅正文必填。
- [x] 从灵感创建选题，确认真实来源可见；再直接创建选题，确认不伪造来源。
- [x] 切换待选/暂存和备用星标，确认两者独立。
- [x] 开始创作，同时选择公众号小绿书与视频号视频，只得到一个内容母体和两个分支。
- [x] 再次开始创作跳转已有内容，不重复创建。
- [x] 保存共享表达和叙事线；从内容库打开同一内容查看来源。
- [x] 后续添加公众号图文；停止一个分支，再添加同种形式恢复原记录，其他分支不受影响。
- [x] 进行中每篇只显示一行母内容，包含各渠道摘要和下一步。
- [x] 切换项目，不展示原项目内容；直接请求其他项目 URL 返回 404。
- [x] 小红书不可选择；待我审核仅提示后续阶段开放。

## API 与技术验证

V2 页面：`/projects/{project}/in-progress|inspirations|topics|content|review`，内容详情 `/projects/{project}/content/{item}`。

V2 API：`/api/projects/{project}/inspirations`、`topics`、`topics/{topic}/start`、`items`、`items/{item}`、`items/{item}/branches`、`items/{item}/branches/{branch}/stop`、`in-progress`。开始创作传 branches 数组，非法组合整体返回 422，重复开始返回 409 和已有 content_item_id。

验收命令：PHPUnit SQLite 全量、`phpunit.mysql84.xml` MySQL 真库验证、`npm run typecheck`、`npm run build`。结果与具体快照见执行台账，未通过远程 CI 前不宣称 CI 绿色。

## 后续阶段

本阶段不实现图片页、AI 导入、视频、文章制作或真实审核。视频阶段页面只保留整批视频制作说明、完整旁白稿、每片段动态/时长/备注，其余继承或系统生成。ProductionTask/ChannelTask 暂存用于旧 V1 路径，V2 API 不依赖，按 Plan 6 清理。
