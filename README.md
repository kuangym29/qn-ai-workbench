# QN AI 内容工作台 Lite V1.0

面向单一运营团队的内容生产工作台：`Laravel 13 + Inertia + Vue 3 + TypeScript + Vite`，数据落在 `MySQL 8.4 LTS`。
从立项到渠道发布准备的一条主链已在 Lite V1.0 打通——选题、正式文案、查重、生产任务、共享视觉资产、渠道资产绑定与发布状态推进，都可以在本机真实跑通。

> **发布状态：V1 技术验收已完成，Release Closeout 进行中。**
>
> - **MySQL 8.4 Runtime Gate = Passed**——最后一个数据库 Runtime 技术硬 blocker 已关闭。
> - **Final Release Closeout = Pending**——尚未创建 `v1.0` tag，也尚未创建 GitHub Release。
>
> V1 基线已在隔离的 `MySQL 8.4.11` 环境完成真实 Runtime 验收：Run `37343124218` / Job `111874925038`，DuplicateReview 定向 `13 tests / 122 assertions`、Full MySQL `283 tests / 3281 assertions`、Concurrency Runtime Proof Passed、Gate 与 observer 退出码均为 `0`、cleanup 已验证。
> 完整 Gate 设计与安全边界见 [`docs/MYSQL84_TESTING.md`](docs/MYSQL84_TESTING.md)；端到端验收范围与结果见 [`docs/V1_FINAL_E2E_ACCEPTANCE.md`](docs/V1_FINAL_E2E_ACCEPTANCE.md)。

## Lite V1.0 能做什么

### 业务主链

```text
Project → ContentColumn → Topic → ContentItem → ProductionTask → ChannelTask
```

`ContentColumn` 即工作台语境中的“栏目”，在文档与界面里常简称 Column。
`Project` 是最高数据作用域边界，所有后续业务查询都必须经当前 Project 限定。`ContentItem` 另挂 `ContentPage` / `ContentCopyRevision` / `SourceReference`，`ProductionTask` 另挂共享视觉 `Asset → AssetVersion → File`。

### 已完成能力

| 能力 | 说明 | 相关文档 |
| --- | --- | --- |
| Auth Lite | Laravel `web` guard + 服务端 Session + 同源 CSRF；登录限流 6 次/分钟/IP；登录成功轮换 Session，登出失效 Session 并轮换 token；guest 访问业务 API 返回 401 JSON、访问页面跳转登录。**没有公开注册入口**，首个用户需在控制台创建 | `docs/DEV-AUTH-LITE_BACKEND.md`、`docs/DEV-W11_AUTH_LITE_UI_PREP.md` |
| Formal Copy / Revision | `ContentPage` 稳定页身份、`ContentPageVersion` append-only 版本、`ContentCopyRevision` 整篇确认；confirm 是进入正式状态的唯一入口，正式版本不可改不可删 | `docs/DEV-006A_CONTENT_PAGE_DESIGN.md`、`docs/DEV-007A_PAGE_API_CONTRACT.md` |
| Duplicate Review | 历史语料基线 + Exact / Overlap 分层候选 + 人工决策（`confirmed_duplicate` / `ignored`）；决策以 `ContentItem::lockForUpdate()` 串行编号，复核 UI 可用 | `docs/DEV-D11_DUPLICATE_REVIEW_API.md`、`docs/DEV-W10_DUPLICATE_REVIEW_UI.md` |
| Production Task | 任务固定绑定当前正式 Revision；`use-current-copy` 换版会重置图稿状态，有渠道时只能走 `restart-with-current-copy` 整链重置；四个状态维度互不推导 | `docs/DEV-009A_PRODUCTION_TASK_API.md` |
| Shared Asset / AssetVersion | `Asset` 是按 ProductionTask + Page + Role（`clean_master` / `copy_master`）的稳定槽位，`AssetVersion` 为 append-only 实际版本并记录依据的 Revision；Model 层禁止 UPDATE / DELETE | `docs/DEV-010A_SHARED_VISUAL_ASSET_CORE.md`、`docs/DEV-W08_ASSET_UI.md` |
| Channel Asset Binding | 渠道绑定共享资产版本，公众号取 `copy_master`、视频号取 `clean_master`，反向角色绑定被拒；绑定不完整时发布与视频审核被拦 | `docs/DEV-011A_CHANNEL_ASSET_BINDING.md`、`docs/DEV-W09_CHANNEL_ASSET_BINDING_UI.md` |
| Channel Task | 公众号 / 视频号引用同一 Production，`artwork` / `video` / `publish` 三维状态互不推导，支持排期与发布确认；**不调用任何真实微信 API** | `docs/DEV-W05_CHANNEL_TASK_API.md`、`docs/DEV-W06_PRODUCTION_CHANNEL_UI.md` |
| Source Reference | 按 Project 或 ContentItem 管理来源引用，保存来源身份与相对引用，不承担文件上传或同步 | `docs/DEV-008A_SOURCE_REFERENCE_IMPLEMENTATION.md`、`docs/DEV-W07_SOURCE_REFERENCE_API_UI.md` |
| Golden Workflow 验收 | 一条覆盖 401 → 建项目到发布 → 换版 → 登出的端到端自动化测试，走真实 HTTP / 路由 / 服务与数据库 | `docs/V1_FINAL_E2E_ACCEPTANCE.md` |

### 当前边界（已知、非阻断）

- **没有项目级成员或角色授权**：任何已登录用户都能访问任何 Project，Session 里的 `current_project_id` 只决定数据作用域，**不等于访问控制**。Project 是数据作用域边界，不是 RBAC 权限边界。
- **SourceReference 的校验是分层的**：常规 SourceReference 负责保存来源身份与相对引用，不上传、不同步文件；特定历史导入流程会按 Manifest 解析当前物理位置并验证源文件可读取。
- **历史来源身份与物理位置分离**：历史 `source_path` 保留为来源身份（用于唯一识别、既有数据兼容与审计追溯），不因文件移动而改写；实际文件读取通过解析层（如 `currentSourcePath()`）指向当前唯一物理位置，不保留旧目录兼容入口。
- **查重阈值未充分校准**：3-gram Jaccard 只作为候选方法，最终结果需人工确认。
- **渠道发布是内部工作台状态**：不对接微信真实发布接口、不保存 access_token。
- **Asset 目前是 filesystem-local / metadata-only**：文件本体随仓库外部目录管理，V1 不含对象存储与版本化文件服务。

这些边界的完整论证见 [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) 的“架构风险与待决策点”。

## 运行环境

- **PHP >= 8.4.1**（以当前 `composer.lock` 的实际平台要求为准）、Composer 2
- MySQL 8.4 LTS
- Node.js `^20.19` 或 `>=22.12`、npm

> `composer.json` 的 `require.php` 声明较宽松，不能代表当前 lock 文件下生产依赖的真实要求。
> 部署或安装依赖前后，请以 lock 为准做平台校验：
>
> ```sh
> composer check-platform-reqs --lock --no-dev   # 安装前：核对 lock 的平台要求
> composer check-platform-reqs --no-dev         # 安装后：核对实际运行环境
> ```

## 本地启动

1. 创建 MySQL 数据库 `qn_ai_workbench`，字符集 `utf8mb4`。
2. 安装依赖：`composer install` 与 `npm install`（版本锁定场景用 `npm ci`）。
3. `cp .env.example .env`，填写 `DB_HOST`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`。**不要提交 `.env`。**
4. `php artisan key:generate`，然后 `php artisan migrate`。
5. 创建首个登录用户（没有注册接口，需控制台执行）：

   ```php
   php artisan tinker
   >>> $email = 'operator@example.com';
   >>> $name = 'Operator';
   >>> $password = \Laravel\Prompts\password('Initial password');
   >>> \App\Models\User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);
   ```

6. 分别运行 `php artisan serve` 与 `npm run dev`，浏览 `http://127.0.0.1:8000`——未登录会跳转到 `/auth/login`。

前端生产构建：`npm run build`。本仓库不包含部署脚本；正式部署请见下方“部署提示”。

## 测试入口

```sh
composer test            # = artisan config:clear + artisan test（默认 SQLite 内存库）
vendor/bin/pint --test   # PHP 代码风格
npm run typecheck        # vue-tsc
npm run build            # 前端构建
```

普通测试默认使用 SQLite `:memory:`，**不会触碰本地 MySQL 数据**——它不是发布验收的最终依据。当前普通 SQLite 基线为 **270 passed / 3209 assertions**；发布前的真实数据库复验走下方 MySQL 8.4 Release Gate。

Golden Workflow 端到端主链单独统计：`tests/Feature/V1GoldenWorkflowTest.php`，**2 passed / 300 assertions**，不与 Golden Corpus 或 MySQL Runtime suite 合并计数。

提交前请先跑 Lint 与类型检查，并检查 `git status` 是否混入了无关产物。

## MySQL 8.4 Release Gate 入口

`scripts/test-mysql.sh all` 是发布前复验与环境验证的**正式 MySQL 8.4 Gate 入口**。V1 基线已经在隔离的 `MySQL 8.4.11` 环境完成并通过真实 Runtime 验收；日常复验仍可用同一入口。

Gate 需要 **Docker（或 Podman，用 `CONTAINER_RUNTIME=podman` 覆盖）**。脚本不会自动安装任何软件，runtime 缺失时以退出码 `127` 明确失败（fail closed），不会降级继续。

```sh
# 一次性准备
cp .env.mysql-testing.example .env.mysql-testing
php artisan key:generate --env=mysql-testing

scripts/test-mysql.sh all     # 启动 → 校验 → migration → 定向测试 → 全量 suite → 销毁
```

分步可用：`up` / `verify` / `suite` / `logs` / `down`。

隔离与安全性（详见 [`docs/MYSQL84_TESTING.md`](docs/MYSQL84_TESTING.md)）：

- compose 固定 project name `qn-workbench-mysql84-gate`，只作用于本 Gate，是一次性的 disposable 环境；
- 宿主机端口 `3399` 且仅绑 `127.0.0.1`，避开既有的 3306 / 3307 实例；
- 库名固定 `qn_workbench_test`、账号 `qn_test`（不用 root），数据落在容器 `tmpfs`，`down -v` 后无残留；
- **不使用生产数据库，也不要把生产连接指向本 Gate**；
- 三道安全闸：生效 target 逐项校验（`config()` 实际值，不是 grep 文件）、PHPUnit bootstrap 前的真实 PDO 自证、测试进程内的连接自证——任一条不满足即中止。

## 部署提示

本仓库不包含生产部署自动化脚本。完整部署清单见 [`docs/V1_DEPLOYMENT_CHECKLIST.md`](docs/V1_DEPLOYMENT_CHECKLIST.md)，当前交付与发布状态见 [`docs/V1_RELEASE_DELIVERY.md`](docs/V1_RELEASE_DELIVERY.md)。正式环境至少注意：

- 全站 HTTPS；
- `APP_KEY` 在首次部署时生成并**长期保留**，生产升级不得重新生成（会使既有密文与签名失效）；
- 生产数据库不允许 root 账号或空密码；
- 先备份再执行 `php artisan migrate`；**生产环境禁止 `migrate:fresh`**；
- 前端执行正式构建 `npm run build`；Web 服务器根目录指向项目 `public/`，应用请求由 `public/index.php` 处理，构建后的静态资源位于 `public/build/`；
- Asset 目前是 filesystem-local / metadata-only 体系，需自行保证文件目录的持久化与备份。

## 关键文档索引

| 文档 | 用途 |
| --- | --- |
| [`docs/AI_DEV_RULES.md`](docs/AI_DEV_RULES.md) | 协作规则，改动前先读 |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | 系统边界、领域骨架、内容生产原则、风险与待决策点 |
| [`docs/DATABASE_BASELINE.md`](docs/DATABASE_BASELINE.md) | 数据库表结构、约束与迁移基线 |
| [`docs/DEV_TASKS.md`](docs/DEV_TASKS.md) | 开发任务与进度台账 |
| [`docs/V1_FINAL_E2E_ACCEPTANCE.md`](docs/V1_FINAL_E2E_ACCEPTANCE.md) | V1 最终端到端验收范围、方法与结果 |
| [`docs/V1_ACCEPTANCE_CHECKLIST.md`](docs/V1_ACCEPTANCE_CHECKLIST.md) | V1 技术验收归档与 Gate 状态 |
| [`docs/V1_RELEASE_DELIVERY.md`](docs/V1_RELEASE_DELIVERY.md) | V1 交付范围、边界与发布状态 |
| [`docs/V1_DEPLOYMENT_CHECKLIST.md`](docs/V1_DEPLOYMENT_CHECKLIST.md) | V1 部署、升级、Smoke、回滚操作清单 |
| [`docs/MYSQL84_TESTING.md`](docs/MYSQL84_TESTING.md) | MySQL 8.4 Release Gate 的设计、命令与安全边界 |

按开发任务细分的设计 / 契约文档集中在 `docs/` 下，命名形如 `DEV-<模块>_*.md`，按需查阅。

## 协作约定

改动前先阅读 [`docs/AI_DEV_RULES.md`](docs/AI_DEV_RULES.md)、[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)、[`docs/DATABASE_BASELINE.md`](docs/DATABASE_BASELINE.md) 与 [`docs/DEV_TASKS.md`](docs/DEV_TASKS.md)。

Project 是最高数据作用域边界，跨 Project 引用一律拒绝。文案、图稿、视频、发布四个维度不得相互推断或自动推进，任何例外都必须先在任务书里明确约定、再落到代码。
