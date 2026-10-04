# DEV-D11｜Duplicate Review API

## 范围与职责

本任务提供同一 Project 内的逐字段查重候选读取与人工审核决策追加。D09 的 Normalizer / Similarity / Compare 与 D10 的 CorpusBuilder / CandidateFinder 保持纯 PHP、未修改；本 API 只负责从数据库读取页面版本、转成 snapshot array、调用 Builder / Finder 并返回结果。Overlap 只是待人工审核的候选，不会自动改文案或状态。

## Query 与 Corpus

- Query 由服务端从当前 ContentItem 各页最大 `version_no` 的 ContentPageVersion 生成，且只取 `copy_revision_id = null` 的 Working 草稿。若最新版本是正式快照，该页没有 Query；整篇都没有 Query 时正常返回空结果。
- Query 的页面序号和类型来自当前 ContentPage；可查字段由 D10 Builder 的 PageType 映射决定。空白字段与 fixed_back_cover 不形成 Query。
- Corpus 仅取同 Project 下 `copy_revision_id != null` 的正式 ContentPageVersion，包含其它 ContentItem 及当前 Item 的历史 Revision。页面序号和类型使用正式快照字段，不取后来改动的当前 Page。其它 Project 与其它篇目的 Working 草稿不入 Corpus。
- Finder 保持 same-field、self-exclusion、三层匹配优先级及默认每 Query 20 个候选。GET 可用 `?limit=1..100` 指定每条 Query 的上限。当前 D10 阈值为初始值，尚待更多真实样本校准。

## HTTP 契约

两条路由均要求 Session 中的当前 Project 与 URL Project 一致，且逐级校验 Column → Topic → Item：

```
GET  /api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/duplicate-review
POST /api/projects/{project}/columns/{column}/topics/{topic}/items/{item}/duplicate-review/decisions
```

GET 返回 `{data:{content_item_id,query_source:"working",corpus_source:"formal_history",query_count,candidate_count,queries}}`。每条 Query 为 `{content_page_id,page_version_id,page_no,page_type,field,text,candidates}`。Candidate 包含 `match:{content_item_id,content_page_id,page_version_id,copy_revision_id,revision_no,page_no,page_type,field,text}`、`match_kind`、`original_exact`、`normalized_exact`、`overlap_score`、`threshold`、`latest_decision`。后者为空或 `{id,decision_no,decision,note,created_at}`。

POST 请求只接受 `query_page_version_id`、`query_field`、`match_page_version_id`、`match_field`、`decision`、可空 `note`。决定值仅为 `confirmed_duplicate`、`ignored`、`false_positive`。服务端派生 Project/Item 和 `decision_no`，拒绝客户端提交这些系统字段。成功返回 201 与新决策 `data`；没有 PUT / PATCH / DELETE。

## 审核历史与边界

`duplicate_review_decisions` 每次追加一行，不覆盖旧决策；同一 Query version + field / Match version + field 的 `decision_no` 从 1 递增，数据库唯一键防止重复。事务内先锁定所属 ContentItem，再重新核验版本和候选并分配序号；同一 Item 的并发追加按同一锁串行。Model 禁止 UPDATE / DELETE。

POST 重新确认 Query 是当前最大版本的草稿、属于 URL Item；Match 属于同 Project 的正式 Revision；两端字段受 PageType 映射支持，且 Finder 当前确实返回此配对。未知版本、跨 Project 或错误 Item 归属返回 404，已过期 Query、非正式 Match、非法字段或非候选配对返回 422。读取其他 Item 的正式版本仅限同 Project；ProjectContext 本身不是用户身份授权。

Decision 永远绑定提交时的 `query_page_version_id`。新草稿成为最大版本后，旧 Query 的历史 Decision 保留，但旧版本再次 POST 返回 422；新版本是新的 Query 身份，起始 `decision_no = 1`。GET 只对当前返回的 Candidate 挂载同一 `query_page_version_id + query_field + match_page_version_id + match_field` 配对中 `decision_no` 最大的 `latest_decision`，不按 Item、Page 或文本合并。事务中的 Item 行锁与配对序号唯一键共同保护并发；插入时若仍发生唯一冲突，事务回滚并返回受控 422，不覆盖旧决定。

数据库以复合外键保证决策 Query 指向同 Project、同 ContentItem 的 PageVersion，Match 指向同 Project 的 PageVersion。约束并不替代 POST 的“当前 Working / 正式 Revision / 候选”业务检查。

## 本轮不做

不开发 Vue UI、Vector DB、Embedding、跨字段语义查重、AI 自动裁决、自动修改或删除文案、Auth Lite、外部搜索。不会更改 D09/D10 核心或 Golden Fixture。
