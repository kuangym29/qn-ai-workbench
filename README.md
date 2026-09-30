# QN AI 内容工作台 Lite V1.0

DEV-001 开发基线。当前仅有技术栈连通页和 Project/Column 数据库迁移；业务操作尚未实现。

## 运行环境

- PHP 8.3+、Composer 2
- MySQL 8.4 LTS
- Node.js 20.19+ 或 22.12+、npm

## 本地启动

1. 创建 MySQL 数据库 `qn_ai_workbench`，使用 `utf8mb4`。
2. 执行 `composer install` 和 `npm ci`。
3. 复制 `.env.example` 为 `.env`，填写 `DB_HOST`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`。不要提交 `.env`。
4. 执行 `php artisan key:generate`，然后执行 `php artisan migrate`。
5. 分别运行 `php artisan serve` 与 `npm run dev`，浏览 `http://127.0.0.1:8000`。

前端生产构建：`npm run build`。正式部署需另行配置 Web 服务器、环境变量及迁移；本任务不包含部署。

## 质量检查

```sh
php artisan test
vendor/bin/pint --test
npm run typecheck
npm run build
```

测试默认使用内存 SQLite，不会操作本地 MySQL 数据。提交前检查 `git status`，不要把其他代理的 `fixtures/` 与 `FIXTURES_README.md` 混入 DEV-001。

## 协作入口

Codex、WorkBuddy、豆包在改动前先阅读 [AI 开发规则](docs/AI_DEV_RULES.md)、[架构基线](docs/ARCHITECTURE.md)、[数据库基线](docs/DATABASE_BASELINE.md) 和 [开发任务](docs/DEV_TASKS.md)。Project 是最高数据隔离边界。本轮不要开始 DEV-002。
