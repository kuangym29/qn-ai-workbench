# 开发任务清单

## DEV-001：项目初始化与开发基线（本分支）

- Laravel 13、Vue 3、Inertia、TypeScript、Vite 工程与最小启动页。
- MySQL 8.4 环境示例；Project/Column migration 草案。
- PHP 测试、前端类型检查与构建命令；README 本地启动说明。
- 四份统一开发文档。完成后在 `codex/DEV-001-bootstrap` 提交，等待审核，不直接合并 `main`。

验收：`php artisan test`、`vendor/bin/pint --test`、`npm run typecheck`、`npm run build`；有 MySQL 8.4 实例时另跑 `php artisan migrate`。

## DEV-002 建议（尚未启动）

建立 Project 进入流程与最小服务端 Project 上下文，定义用户可访问 Project 的来源；在此基础上实现 Column 的项目内列表与创建，并为跨 Project 访问写拒绝测试。先确认身份方案和项目成员关系，不提前实现 Topic 及内容生产模块。

## 后续待拆分

Topic 与 Content Item、逐页内容与版本、共享视觉资产、Production Task、Channel Task、查重与发布流程分别规划。每项先补充数据归属与验收标准，再分配独立分支；不得由 DEV-001 顺手实现。
