# DEV-007A｜ContentPage / CopyRevision API 数据契约

基于 DEV-005 同源 Session + CSRF 模式。先调用 `POST /api/projects/{project}/select` 选择当前 Project。以下路径中的 `{project}` 必须与 Session `current_project_id` 一致，并逐级验证 `Project → ContentColumn → Topic → ContentItem → ContentPage`。所有响应为 JSON `{"data": ...}`。不提供页面、版本或修订删除/覆盖路由。

## 路由

公共前缀：`/api/projects/{project}/columns/{column}/topics/{topic}/items/{item}`

| 方法 | 后缀 | 用途 |
| --- | --- | --- |
| GET | `/pages` | 当前篇目页面列表，`page_no ASC` |
| POST | `/pages` | 创建稳定页面身份 |
| GET | `/pages/{page}` | 页面详情 |
| PATCH | `/pages/{page}` | 仅修改页面类型 |
| POST | `/pages/{page}/drafts` | 追加一条草稿版本 |
| POST | `/pages/reorder` | 完整重排页面 |
| POST | `/copy/confirm` | 原子确认整篇，返回完整正式快照 |
| GET | `/copy/revisions` | 正式修订列表，`revision_no DESC` |
| GET | `/copy/revisions/{revision}` | 指定修订及完整页面快照 |
| GET | `/copy/current` | 最大 `revision_no` 的当前正式修订；无修订时 `{"data":null}` |
| GET | `/copy/working` | 当前页面及各页最大 `version_no` 的文案，`page_no ASC` |

## 请求

创建 Page：`{"page_no":1,"page_type":"cover"}`。页号必须是正整数，在当前 ContentItem 内唯一。合法 PageType：`cover`、`content`、`column_closing`、`fixed_back_cover`。创建页面不自动产生 Revision 或空 Version。已确认篇目增页后 `copy_status` 回到 `editing`。

PATCH Page：`{"page_type":"content"}`。`page_type` 必填且必须是上述合法值；`page_no` 禁止通过 PATCH 修改，类型实际变更后已确认篇目回到 `editing`。页面归属由 URL 已校验的篇目决定，不接受 `project_id`、`content_item_id`、`topic_id`、`content_column_id`。

追加草稿：可提交 `column_label`、`cover_title`、`cover_subtitle`、`page_title`、`page_small_text`、`closing_line`、`note`；各字段可省略、可为 `null`，非空时必须是字符串。未知字段和 `copy_revision_id`、`version_no`、`page_no_snapshot`、`page_type_snapshot` 等服务端字段一律 422。服务端调用 `ContentCopyService::appendDraft`，未提交字段继承上一版本；每次插入新版本并将篇目文案状态置为 `editing`。

重排：`{"page_ids":[3,1,2]}`。数组必须完整包含当前篇目所有 Page ID，不能重复或包含外篇页面；服务端调用 `ContentCopyService::reorderPages`，成功返回新顺序的 Page 列表，最终页号连续 1…N。历史正式快照页序不变。

确认：无请求体；服务端调用 `ContentCopyService::confirmContentItem`。每页须有版本，页面编号连续且文案符合 PageType 最小规则。成功后新增完整整篇 Revision 快照，未修改页也复制到新修订，`copy_status = confirmed`。校验失败返回 422，事务不留下部分修订。

## 响应字段

Page：`id`、`project_id`、`content_item_id`、`page_no`、`page_type`、`latest_version`、`created_at`、`updated_at`。列表不包含全历史版本；`latest_version` 在无版本时为 `null`。Working 使用同一 Page 形状，各页 `latest_version` 取最大 `version_no`，可为草稿或正式快照。

PageVersion：`id`、`project_id`、`content_item_id`、`content_page_id`、`version_no`、`copy_revision_id`、`page_no_snapshot`、`page_type_snapshot`、上述七个文案/备注字段、`created_at`、`updated_at`。草稿的 `copy_revision_id` 及两个 snapshot 字段为 `null`。

Revision 列表项：`id`、`project_id`、`content_item_id`、`revision_no`、`confirmed_at`。Revision 详情、Confirm 和 Current 额外返回 `page_versions`，是该修订的完整正式 PageVersion 数组，按 `page_no_snapshot ASC`。无当前正式稿时 Current 返回 `{"data":null}`。

所有状态和 PageType 均输出正式字符串，不暴露 PHP Enum 对象；时间为 UTC ISO 8601 字符串或 `null`。POST 创建/追加/确认成功为 201，其余成功为 200。

## Scope 与错误

Session Project 与 URL 不符，任一 Column、Topic、Item、Page、Revision 不属于其路径祖先，均返回 404；写请求在 Validation 前也先校验祖先链，不暴露其他 Project 数据是否存在。输入无效、伪造归属字段、未知草稿字段、不完整或重复重排、确认时文案不符合规则，返回 422，格式沿用 `{"message":"...","errors":{"field":["..."]}}`。Revision 和正式 PageVersion 只读，未来编辑必须追加草稿并再次整篇确认。

本契约不含 Vue、Importer、Source Reference、Asset、渠道专属文案或权限矩阵。Session Project 是业务作用域边界，不等于用户成员授权。
