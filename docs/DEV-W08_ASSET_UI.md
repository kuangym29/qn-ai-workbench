# DEV-W08｜Production Workspace 共享视觉资产 UI

在 DEV-010A 资产核心数据层与 DEV-010B API 之上，把共享视觉资产接入 Production 工作台：用户可以在单篇制作页面里查看逐页视觉母版矩阵、登记新版本、查看只读历史。

**本轮 Migration 数量为 0**，未修改任何后端文件（`app/**`、`database/**`、`routes/web.php`、`tests/Feature/Asset*`），三份中央共享文档也留给 ChatGPT 统一同步。

## UI 位置

共享视觉资产是**单篇 Production 的组成部分**，没有独立的全局 Asset 页面。顺序为：

```
Breadcrumb / PageHeader
篇目与正式文案概览
共享图稿制作（Production task + stale 警告）
共享视觉资产          ← 本轮新增
渠道任务（公众号 / 视频号）
```

## API 契约

作用域为 `Project → Column → Topic → ContentItem → ProductionTask`：

| 方法 | 路径 | 返回 |
| --- | --- | --- |
| GET | `/production/assets` | `AssetWorkspace`（逐页资产矩阵） |
| GET | `/production/assets/{asset}` | `AssetDetail`（槽位 + 完整版本历史） |
| POST | `/production/assets/versions` | `AssetVersion`（追加一个已登记的版本） |

adapter 为 `resources/js/api/assets.ts`，统一解 `{"data": ...}`，无 mock、无降级到不存在的接口。

### 何时加载

**ProductionTask 不存在时不请求资产 API**——资产挂在制作任务下，没有制作任务就没有可绑定的资产。ProductionTask 存在时才调用 `assetsApi.workspace()`。

刷新统一走既有的 `reload()`，因此以下操作后资产视图都会自动重新拉取：创建 Production、图稿状态变更、`use-current-copy`、`restart-with-current-copy`、以及所有渠道操作。其中 `use-current-copy` 与 `restart-with-current-copy` 尤其关键——它们会改变 `copy_revision_id`，资产版本正是按 pinned Revision 归档的。

## pinned Revision 的页面矩阵

`AssetWorkspace.pages` 是**制作任务所绑定 Revision 的快照**，页面顺序与 `page_type` 一律按 API 返回渲染，**不依据当前 ContentPage 重新排序**——正式文案确认之后页面可能已经变动，用实时页面推导会与历史制作依据不一致。

每个页面固定两列：

- **无文案底图**（`clean_master`）——视频号使用
- **有文案定稿图**（`copy_master`）——公众号使用

## current 与 latest

槽位上的两个版本指针含义不同，UI 不得混淆：

- `current_version`：属于**制作任务当前 pinned Revision** 的版本，是「正在用于制作」的那一个；
- `latest_version`：所有 Revision 中最新的一个，**可能属于更早的 Revision**。

因此存在三种展示组合：

| 情形 | 展示 |
| --- | --- |
| `current_version` 非空 | 显示 `vN`、`Revision N`、原始文件名、`disk : path`、尺寸（若有 `width`/`height`）、大小（若有 `size_bytes`）、备注 |
| `current_version` 非空，且 `latest_version.id ≠ current_version.id` | 追加一行「历史最新：Revision X · vN」 |
| `current_version` 为空，但 `latest_version` 非空 | 主位显示「当前 Revision 尚无版本」，下方仍显示「历史最新 Revision X · vN」 |

最后一种常见于制作任务切到 Revision 2 之后：历史图稿还在，但新 Revision 尚未登记。**不会自动沿用历史版本**，必须重新登记。

没有槽位时显示「未登记」，并提供「登记版本」按钮。首次 POST 由后端一并创建 Asset 槽位、File 与 AssetVersion，前端不预建资产。

## 登记版本（不是上传）

Dialog 顶部明确提示「当前只登记文件定位和版本信息，不会上传或读取文件。」表单字段：存储盘（默认 `local`）、存储路径、原始文件名，以及可选的 MIME、字节数、宽、高、备注。

**槽位与角色由用户点击的位置决定**，Dialog 内不允许修改 `content_page_id` 与 `role`——避免登记到与点击意图不一致的槽位。

页面**不提供**文件选择器、拖拽、上传按钮、图片预览或本地磁盘浏览：DEV-010B 尚无真实 Upload 能力，做假上传界面只会误导。

### 路径与文件名校验

`storage_path` 在前端镜像服务端的基础规则：trim → `\` 转 `/` → 压缩重复 `//` → 去掉开头 `./`；拒绝空、绝对路径、UNC、`..`、规范化后为空。`original_name` 拒绝空、`.`、`..` 与任何路径分隔符，且**不从 `storage_path` 自动截取**。

前端只做 UX 镜像，服务端始终是最终 Gate。422 只弹 Toast，Dialog 保持打开且不清空用户输入。

### 成功后

POST 成功即 Toast「已登记资产版本」，关闭 Dialog，并重新 GET 资产工作区。前端不手工 `version_no + 1`，也不局部猜测服务端状态。

## 历史只读

已存在的槽位提供「查看历史」，按需 GET `assets/{asset}` 并以只读弹层展示每个版本的 `vN`、`Revision N`、文件名、存储位置、尺寸、备注与创建时间。**不提供编辑、删除、回滚或设为当前**——资产版本是制作依据的历史事实。

## stale 与 legacy

**Production stale（`is_copy_revision_current = false`）时仍允许登记版本**，并在区块顶部提示「本次登记会归入制作任务当前绑定的 Revision N；新的正式文案不会自动套用旧图稿。」这是后端的正式规则：stale 制作任务依然可以向 pinned Revision 追加资产版本。

**图稿审核门禁不受影响**：登记资产不会自动调用 `updateArtwork()`，Asset 与 `artwork_status` 相关但独立；本轮也**不实现「没有所有图片不能审核通过」这类完整度门禁**。

**legacy 空 Revision**：`copy_revision_id = null` 的历史制作任务显示「此历史制作任务尚未绑定正式文案版本，无法登记资产。」并禁用登记入口，页面其余部分保持稳定。

## 错误处理

沿用 DEV-W06.1 的分工：

- **加载失败**（资产区块自身）→ 只在该区块显示错误文案，不影响制作与渠道区域；
- **写操作 422** → 只 Toast，不切 ErrorState，Dialog 保持打开；
- **作用域 404** → Toast 后 `router.visit('/projects')`，绝不根据 URL 自动 `selectProject`。

## ProjectContext

Session 当前 Project 仍是唯一作用域来源，本轮未改动任何相关逻辑。

## 不做的事

- 不做文件上传、拖拽、图片预览、本地文件选择器；
- 不做资产与渠道（公众号 / 视频号）的绑定关系；
- 不做资产完整度审核门禁；
- 不提供资产编辑、删除或回滚；
- 不新增 Migration，不修改后端。
