# DEV-008A｜SourceReference Lite 核心数据层

本任务依据 `DEV-D07_SOURCE_PROVENANCE_AUDIT.md` 的实源审计实现最小来源引用。只记录来源文件路径及角色，不读取文件、不导入内容。品牌源目录仍为历史文案权威所在；数据库的 SourceReference 是指向它的引用。

## Schema 与关系

新增 `source_references`：`id`、`project_id`、可空 `content_item_id`、`role`、`authority`、`source_path`、可空 `note`、`created_at`、`updated_at`。数据库字段为字符串，不使用数据库 ENUM。路径为品牌源根目录下的相对路径，`source_path` 使用 `string(1000)`，不建立路径索引或唯一键。

`project_id` 是 Project 外键；`(project_id, content_item_id)` 复合外键指向 `content_items(project_id, id)` 的**复合唯一键**。可空 `content_item_id` 表示 Project 级来源；非空时数据库禁止指向其他 Project 的 Item。普通索引为 `(project_id, role)` 与 `(project_id, content_item_id, role)`。无 Role/Scope 数据库 CHECK；后续受控写入层使用 Enum helper 校验语义。

两个外键都采用级联删除：Project 删除时清理全部来源引用；ContentItem 删除时只清理其 Item 级引用，Project 级引用仍在。已在 SQLite 和 MySQL 8.4 实测直接与间接级联路径。产品层尚无删除 API；开放删除/归档前仍需独立审查历史来源保留策略。

## Enum、角色与作用域

`SourceRole` 与 `SourceAuthority` 均为 PHP string backed Enum。Model 从数据库读取时分别 cast 到对应 Enum。Factory 默认生成合法的 Project 级 `content_ledger` 引用，并提供五种角色 state；创建 Item 级来源时须绑定同一 Project 与 ContentItem。

| Role | Authority（`defaultAuthority()`） | `isContentItemScoped()` |
| --- | --- | --- |
| `final_image_copy` | `authoritative` | true |
| `source_script` | `evidence` | true |
| `content_ledger` | `index` | false |
| `closing_line_registry` | `reference` | false |
| `navigation_index` | `index` | false |

`authority` 仅有 `authoritative`、`evidence`、`index`、`reference`；`registry`、`navigation` 是角色名的一部分，不是权威等级。

## 刻意不建的字段和约束

Item 级 `final_image_copy`、`source_script` 的 Column 可通过 ContentItem 推导；三个台账跨整个 Project，只是文件内部按栏目组织。因此不建 `content_column_id`。当前四篇 37 页没有独立 Page 文件，不建 `content_page_id`。五种角色的归属结构明确，不用 polymorphic relation。

`source_path` 不唯一：同栏目一个 `最终上图文案.md` 可被多个 ContentItem 引用。同一 Item 也可保留多条不同路径的 `source_script` 历史版本；不建 `unique(content_item_id, role)`。路径只是来源引用，不是正式内容副本。若将来出现独立 Page 文件，再以新迁移评估页面关联。

本轮不含 SourceReference HTTP API、Vue、Importer、Markdown Parser、文件同步、资产、生产或渠道 API。DEV-008B 如需导入，应读取权威 Markdown，逐项对照 D06 映射，并使用 Role helper 校验 authority 和 Item/Project scope。
