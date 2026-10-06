# V1.0 Deployment Checklist

> 本文是 QN AI 内容工作台 Lite V1.0 的**正式部署与发布前操作清单**。它不代表软件已经发布；最终是否创建 `v1.0` tag / GitHub Release，以 Final Release Review 和最终锁定 SHA 为准。

配套文档：[`V1_ACCEPTANCE_CHECKLIST.md`](V1_ACCEPTANCE_CHECKLIST.md)（已发生的技术验收事实）、[`V1_RELEASE_DELIVERY.md`](V1_RELEASE_DELIVERY.md)（交付范围与边界）。

---

## 一、正式运行环境

| 项 | 要求 |
| --- | --- |
| PHP | **>= 8.4.1**（以当前 `composer.lock` production dependencies 的实际要求为准） |
| Composer | 2 |
| Node.js | `^20.19 \|\| >=22.12` |
| npm | 随 Node 发行 |
| 数据库 | MySQL 8.4 LTS |
| 传输 | **HTTPS**（HTTP 不可作为正式生产环境） |

> `composer.json` 的 `require.php` 声明为 `^8.3`，属宽松声明，**不能覆盖当前 lock 文件下生产依赖的真实要求**。

**未验证的环境不在支持范围内。** V1 未在 MySQL 5.7、MariaDB 或其他数据库版本上验证，不得据此部署。

---

## 二、Composer 平台检查（安装前 / 安装后）

安装前，先核对 lock 文件声明的平台要求：

```sh
composer check-platform-reqs --lock --no-dev
```

安装依赖后，再核对实际运行环境：

```sh
composer check-platform-reqs --no-dev
```

两条命令都必须通过。任一条失败即停止部署，不要用降低依赖版本的方式绕过。

---

## 三、依赖安装与前端构建

```sh
composer install --no-dev --optimize-autoloader
npm ci
npm run typecheck
npm run build
```

- **禁止**把 `composer update` 作为生产部署常规步骤。生产必须按 lock 安装，否则实际依赖版本会偏离已验证组合。
- 执行 `npm run build` 生成前端静态资源。Web 服务器根目录必须指向项目 `public/`，应用请求交由 `public/index.php` 处理；构建资源位于 `public/build/`。

---

## 四、APP_KEY（硬安全规则）

### 首次部署

确保生产环境存在 `APP_KEY`。首次初始化可执行：

```sh
php artisan key:generate
```

**只在首次初始化时生成一次。**

### 后续升级

**禁止重新生成 `APP_KEY`。** 更换 `APP_KEY` 会导致既有加密数据、Cookie、签名等失效。

升级时必须复用现有生产 `APP_KEY`，并保留现有 `.env`。

---

## 五、生产 `.env`

- `.env` **不提交 Git**；
- `APP_ENV=production`；
- debug 关闭（`APP_DEBUG=false`）；
- 全站 HTTPS；
- MySQL 使用**专用生产账号**：
  - **禁止 root**
  - **禁止空密码**
- **不复用** MySQL Gate 的 `qn_test` 测试账号；
- **不把 MySQL Gate 测试配置复制到生产**；
- 应用运行账号必须可写 `storage/` 与 `bootstrap/cache/`；同时必须确保 `.env` 不可被 Web 直接访问。

本文不记录任何真实生产密码示例。

---

## 六、数据库升级

**部署前必须先备份生产数据库**，然后才允许执行迁移：

```sh
php artisan migrate --force
```

（命令以实际框架版本为准。）

### 生产环境严格禁止

```sh
php artisan migrate:fresh
```

以及任何等价操作：drop all、fresh、reset production database。**V1 Closeout 不提供任何生产数据重置手段。**

---

## 七、MySQL 8.4 Release Gate 与生产数据库严格分离

正式 Gate 入口：

```sh
scripts/test-mysql.sh all
```

它**只针对一次性、隔离、可销毁的测试环境**：

| 约束 | 值 |
| --- | --- |
| MySQL | 8.4 |
| 主机 / 端口 | `127.0.0.1:3399` |
| database | `qn_workbench_test` |
| user | `qn_test` |
| 存储 | tmpfs |
| 生命周期 | disposable，`down -v` 销毁无残留 |
| 缺失处理 | runtime 缺失即 fail closed（退出码 127） |

> ⚠️ **严禁将 `scripts/test-mysql.sh all` 指向生产数据库。**
> 不得为了发布验收直接在生产库运行 Runtime Gate。

### 已有 Runtime 证据

V1 基线已在隔离的 MySQL 8.4.11 环境通过真实 Runtime 验收（MySQL 8.4.11、Full MySQL 283 / 3281、Concurrency Proof Passed、Runtime Gate Passed）。完整设计、命令与安全边界见 [`MYSQL84_TESTING.md`](MYSQL84_TESTING.md)。

---

## 八、Asset 与文件目录

当前 V1 的 Asset 体系是 **filesystem-local / metadata-only**：

- 数据库中的 `Asset`、`AssetVersion` 与 file reference **不代表文件本体已进入对象存储**；
- V1 **未集成** S3 / OSS / COS 等对象存储，也没有文件自动同步。

生产部署时必须确认：

- 实际文件目录存在；
- 权限正确、可被应用读写；
- 有持久化策略；
- 有备份策略。

> ⚠️ **只备份 MySQL 数据库不足以完整备份 Asset 文件。**
> 文件目录与数据库必须**分别**纳入备份方案，恢复时也要分别考虑。

---

## 九、Queue / Worker

**当前 V1 业务主链不依赖后台 Queue Worker。** 已核查：`app/` 中无 `ShouldQueue` 实现、无 `dispatch(` 调用、无 `Bus::` 派发、无 `app/Jobs` 目录。

因此**不需要**把 `php artisan queue:work` 或 `queue:listen` 作为正式 V1 必启服务。（`composer dev` 中的 queue 进程仅用于本地开发便利。）

---

## 十、部署后 Smoke：分 A / B 两层

### Smoke A：Disposable / Acceptance 环境

可执行**真实写入**，用于验证完整业务写链：

login → Project → Topic → Content Item → Copy / Revision → Production → Channel → Duplicate Review → 其它必要主链。

可使用一次性验收数据，结束后销毁环境。

### Smoke B：Production 安全 Smoke（默认）

**默认只做读操作 + Auth / Session 验证：**

- 登录页加载
- login / session
- `/me`
- Project 列表与当前 Project 读取
- 主要页面加载
- logout / relogin
- HTTPS 生效
- 前端静态资源加载
- console / server error 检查

**默认禁止**在真实生产业务 Project 上执行写操作：创建测试 Topic、创建测试 ContentItem、修改正式文案、修改发布状态、写 Duplicate Review、创建垃圾 Asset、修改真实 ChannelTask。

---

## 十一、生产写入验收的例外规则

若发布负责人坚持在生产环境验证写链：

- 只能创建**专门的 Acceptance Project**，清楚命名，例如 `V1 Release Acceptance`；
- **禁止**使用现有真实业务 Project 做写入测试；
- 验收项目不得与真实运营项目混用。

**优先选择不产生生产写数据。**

---

## 十二、生产验收数据清理

当前 V1 **没有正式业务 DELETE API**（已核查：无 DELETE 路由、无 destroy/delete 控制器方法、无 cleanup 端点）。因此本清单**不写**「测试完成后调用 DELETE API 清理」。

如 Production Acceptance Project 产生了测试数据，清理必须采用**经过审核的数据库操作**：

1. 先确认目标 Project / 数据范围；
2. 先备份；
3. 只处理专用 Acceptance Project；
4. 审核 SQL / DB 操作范围；
5. 不影响任何正式业务 Project；
6. 保留必要审计记录。

> 本 Closeout **不新增** DELETE Project / ContentItem / ChannelTask 或任何 cleanup endpoint。

---

## 十三、部署后检查清单

### Application

- [ ] 首页 / 登录页可加载
- [ ] Auth 正常（login / session / logout / relogin）
- [ ] Project Context 正常
- [ ] 核心页面可加载
- [ ] API 无异常 500
- [ ] frontend assets 正常加载

### Database

- [ ] migration 已完成
- [ ] 连接的是生产库
- [ ] **未**连接 test DB（确认不存在 `qn_workbench_test`）

### Security

- [ ] HTTPS 生效
- [ ] debug 关闭
- [ ] `APP_KEY` 已设置且为原有值（升级场景）
- [ ] `.env` 不可公开访问
- [ ] 数据库账号非 root、非空密码

### Files / Application Permissions

- [ ] `storage/` 对应用运行账号可写
- [ ] `bootstrap/cache/` 对应用运行账号可写
- [ ] Asset 实际文件目录存在
- [ ] 权限正确
- [ ] 持久化策略已确认
- [ ] 备份策略已确认（文件与数据库分别纳入）

---

## 十四、Existing Production Upgrade

已有生产环境的升级，最少按此顺序执行：

1. 锁定部署目标 Git SHA
2. 备份数据库
3. 备份 / 确认 Asset 文件目录
4. **保留现有 `APP_KEY` 与 `.env`**
5. 更新代码
6. `composer check-platform-reqs --lock --no-dev`
7. `composer install --no-dev --optimize-autoloader`
8. `npm ci`
9. `npm run typecheck`
10. `npm run build`
11. `php artisan migrate --force`
12. 执行 **Smoke B**（Production 安全 Smoke）
13. 检查日志 / 错误
14. 才宣布部署完成

> **升级过程中不得重新执行 `key:generate`。**

---

## 十五、回滚原则

- 部署前必须知道**上一个稳定 Git SHA**；
- 应用代码可回滚到前一个稳定版本；
- **数据库 migration 不得盲目执行 destructive rollback**；
- 若数据库变更不可安全逆转，应使用**部署前备份**恢复；
- Asset 文件目录也必须纳入恢复考虑。

> 不把 `php artisan migrate:rollback` 作为无条件的生产回滚方案。

---

## 十六、Release SHA 口径

当前 `3c799c7a6cab2e5813d40b27a4ad240ef169814d` 只是**本任务基线**，**不是最终 Release SHA**。

最终 SHA 只能在以下条件全部满足后锁定：

1. Release / Deployment Docs 进入 main；
2. final smoke / consistency review 通过。

---

## 十七、tag 与 GitHub Release

当前：**tag = 0，GitHub Release = 0。** 本文不创建，也不声称存在。

正式顺序：

```
Final main SHA → Final Release Review → v1.0 → GitHub Release
```

`v1.0` tag 与 GitHub Release 必须指向**同一个最终 SHA**。

---

## 十八、边界声明

本清单**不要求**实现下列能力，V1 Closeout 不扩大功能范围：

文件上传 / 图片预览 / 对象存储 / RBAC / 微信发布 API / DELETE API / 必启 Queue Worker / 部署自动化 / Kubernetes / 生产 Docker 部署。

V1 只记录当前真实实现。
