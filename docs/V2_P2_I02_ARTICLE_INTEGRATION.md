# V2-P2-I02｜公众号图文文章正式集成

## 基线与范围

- Start：`223e2ec3400364620cefd1e966dfafeed76b3d56`。
- B02：`95e473d0e4b0a9d655cab48c1373c73140bf7eb9`。
- C02-R1：`1ecad6f63a329ef396a691887d805389c1f6f3bb`。
- 集成分支：`work/v2-p2-article-integration`；保留两条来源的完整 ancestry。
- 正常 merge 吸收增量，保留最新 Foundation/I01，不做整棵覆盖。解决 routes 导入冲突时同时保留 ImagePost 和 Article；V2 基础回滚测试保留已验收版本。
- 仅 Article 模型、服务、迁移、页面、测试和入口最小接线；没有 Video/Review/ImageBatch 或 main 变更。

## 正式合同

页面：`/projects/{project}/content/{item}/branches/{branch}/article`，从内容篇目的公众号图文分支进入；入口文字为“编辑图文文章”。页面与 API 均检查当前 Project、Item/Branch 归属、article 格式和 wechat_official 渠道。

API base：`/api/projects/{project}/items/{item}/branches/{branch}/article`。

| 方法 | 请求 | 响应 / 行为 |
| --- | --- | --- |
| GET base | 无 | 工作负载本体 `{item, branch, article}`；无文章时 article=null |
| PUT base | 文章五字段 | `{data:{status:"saved"},warnings:[]}`；前端随后 GET |
| POST base/preview | `{raw: string}` | `{item:{changed,fields},article:{added,modified,unchanged},warnings}`，只读 |
| POST base/apply | 已预览的同一 `{raw: string}` | `{data:{status:"applied"}}`；前端随后 GET |

共享字段为 title/core_message/story_line；正式文章字段为 headline/summary/body/image_suggestions/cover_suggestion。没有逐页模型或字段，没有第二套 AI Parser。

preview 的 before/after 支持 JSON 值，配图建议作为整个字段展示格式化 JSON。前端只把后端字段键映射为中文标题，不计算业务 diff。共享字段差异也显示在预览中。

前端空 article 映射为可编辑空表单；空配图保存为 []，不会发送后端不接受的 null。保存和 Apply 完成后以 GET 为正式数据真源。请求期间阻止冲突编辑和重复提交；确认时使用固定的预览原文。

## 必要修复

1. 修正 GET/preview 的多余 data 层，以及 PUT/apply 被当成完整工作负载的错误。
2. 修正 nullable article 与 JSON diff 类型，并接入真实栏目和来源。
3. 保留服务端配图 JSON 的字段、空白及缺省键，避免未编辑保存制造变化。
4. 后端比较 JSON 时递归忽略对象键顺序，保留数组顺序；避免 MySQL JSON 排序产生假 diff/版本。
5. 新增正式页面路由和内容分支入口，修正入口“图片文章”误标。

这些修复均有失败测试再转 GREEN：前端合同 5 RED、正式页面入口 404 RED、配图原值保留 RED、JSON 对象键顺序 RED。

## 冗余清理

删除 ArticleDevPreviewApp.vue、articleDevMock.ts、v2-article-preview.ts、v2-article-preview.html、articleDevMock.test.ts，删除旧 providers 类型与注入路径，移除来源分支新增但已无用途的 tsconfig 选项。C02 文档保留为明确标记的历史记录。

生产 Article 路径不引用 Mock，不解析 raw，不模拟 Apply，不显示技术版本号。

## 真实浏览器验收

使用独立本地 SQLite 验收库与专用本地账号，服务 `http://127.0.0.1:8789`，实际正式页面及真实 API；没有 Mock 响应。

| 项目 | 结果 |
| --- | --- |
| 从内容库 → 篇目 → 正式 Article 分支 | 通过，真实栏目与来源 |
| 空 article | 空表单无异常；数据库 0 篇、0 版本 |
| AI 首次 Preview added | 标题、摘要、正文、封面、配图均新增；共享核心表达显示修改 |
| Cancel | 数据库仍 0 篇、0 版本 |
| 人工编辑 / PUT / 刷新 | 标题、摘要、正文、封面保存并保留；1 版本 |
| AI Preview modified / unchanged | 正文、标题、配图等修改；摘要未变化；数组正常展示 |
| Confirm Apply / GET / 刷新 | 旁白式长正文换行、共享核心表达与配图正常加载；2 版本 |
| 不编辑再 PUT | 仍 2 版本 |
| 再次相同 AI Preview | 五字段全部 unchanged、确认禁用；取消不写库 |
| 非 article 分支 | 正式页面 422；API 同一校验由 ArticleApiTest 锁定 |
| 非公众号渠道 | 正式页面 422；API 同一校验由 ArticleApiTest 锁定 |
| 跨 Project | 正式页面 404；API 由 ArticleApiTest 锁定 |

原 8787 服务失效；首次独立服务因 artisan serve 过滤 DB 环境变量误连旧库，改用 --no-reload 解决。开发热刷新曾清空验收输入，改用正式构建产物后重新完成验收。错误服务未写入旧业务库。

截图位于验收 outputs：`i02-preview.png` / `i02-final.png`。

## 验证记录

- Article backend targeted：30 tests / 106 assertions PASS。
- Article frontend targeted：10 tests PASS。
- SQLite full：374 tests / 3778 assertions PASS。
- Typecheck：PASS。
- Build：PASS。
- diff --check：PASS。
- MySQL 8.4：执行中，最终结果收尾登记。

## 收尾

MySQL gate 完成后发布独立集成分支并建立指向 codex/v2-foundation 的 Draft PR。确认来源 ancestry 后关闭 PR #30，标记 `superseded by V2-P2-I02 integration`，保留 WorkBuddy 分支。不得 merge Foundation/main。
