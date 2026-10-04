# DEV-W11｜Auth Lite 前端预置（UI_PREP）

基线：`origin/main` = `3148a1149cb6f8a5eeae71951d758c2f64a342bf`
分支：`workbuddy/DEV-W11-auth-lite-ui-prep`
阶段：**AUTH_UI_PREP_READY**（后端未实现，尚未联调）

> 本分支与 `workbuddy/DEV-W10-duplicate-review-ui`（远端 `cc8a553`）**完全独立**。
> 两条前端线互不包含对方的任何提交，后续可分别集成、任意顺序合并。

---

## 1. 目标

提前完成 Auth Lite 的前端独立部分，使 Codex 完成 D11 后可直接进入 Auth 后端实现，不必再等前端。

**本任务不是完整 Auth 集成。** 只做前端契约、登录页与展示型用户区；后端、路由、Middleware、Migration、User Model 一律不动。

## 2. 范围冻结

Lite V1.0 只考虑：登录、登出、当前用户、session cookie、CSRF、未登录状态、登录过期、最小项目访问保护。

用户模型沿用 Laravel 现有 `User`，按「少量固定用户、无角色系统」设计。

明确不做：RBAC、权限角色矩阵、Organization、多租户、邀请、注册、找回密码、邮箱验证、SSO、OAuth、MFA、计费。

## 3. 冻结前端契约

为后续 Auth Backend 预留三个端点：

| 方法 | 端点 | 成功 | 失败 |
| --- | --- | --- | --- |
| GET | `/api/auth/me` | `data: { id, name, email }` | 401 |
| POST | `/api/auth/login` | 返回当前 user | 422，`errors.email` |
| POST | `/api/auth/logout` | 204 或空 `data` | — |

**关于 logout 的 204**：现有 adapter 统一走 `.then((r) => r.data.data)`。204 无 body，因此 `authApi.logout()` 写成 `.then(() => undefined)`，**同时容忍** 204 与空 `data` 两种返回，不假定其中一种。这是本任务唯一对既有 adapter 风格的适配点，契约本身未改动。

## 4. 文件清单

| 文件 | 动作 | 行数 | 说明 |
| --- | --- | --- | --- |
| `resources/js/api/auth.ts` | 新增 | 85 | 三个方法 + `AuthUser` / `LoginInput` 类型 + 状态判定helper |
| `resources/js/pages/Auth/Login.vue` | 新增 | 178 | 邮箱 / 密码 / 登录按钮 |
| `resources/js/components/AuthUserMenu.vue` | 新增 | 88 | 纯展示型用户区，props 驱动 |
| `docs/DEV-W11_AUTH_LITE_UI_PREP.md` | 新增 | — | 本文档 |

**零后端改动**：`routes/web.php`、Controller、Middleware、Migration、User Model 及所有 PHP 文件均未触碰。
**未改共享 `resources/js/api/types.ts`**：`AuthUser` 等类型刻意留在 `auth.ts` 内，避免与 W10 并行编辑同一文件产生冲突。

## 5. 认证模型

认证**就是**同源 Laravel session，没有 token 可存。

- `http` 已设 `withCredentials: true`，session cookie 随请求发送。
- axios 自动读取 `XSRF-TOKEN` cookie 并回发 `X-XSRF-TOKEN`，满足 Laravel 的 CSRF 校验。
- 因此 `auth.ts` 中**没有任何** localStorage / sessionStorage 写入、没有 JWT、没有自定义 token 持久化层。

刷新页面后认证状态是未知的，只能重新向服务端询问——这是刻意的，避免客户端持有可伪造的身份。

## 6. Login 页

极简：邮箱、密码、登录按钮。与现有工作台视觉统一（slate 色阶、emerald 强调、`rounded-md` 控件）。

无注册入口、无忘记密码、无社交登录、无营销型大页面。底部只留一句「Lite V1.0 暂不提供注册与找回密码，如需开通请联系管理员」——是说明，不是入口。

成功登录后**不自行决定跳转目标**：`redirectTo` 由后端路由传入，组件只 `emit('authenticated', user)` 并在有 `redirectTo` 时 `router.visit()`。跳去哪由后端守卫决定。

## 7. 状态处理

| 状态 | 表现 |
| --- | --- |
| idle | 表单可用 |
| submitting | 按钮「登录中…」，输入禁用，重复提交被 `if (submitting) return` 拦住 |
| 422 | 消息挂到 `errors.email` 对应字段，输入保留 |
| 401 | 横幅「邮箱或密码不正确。」，输入保留 |
| 419 | 横幅「会话已过期，请刷新页面后重试。」，输入保留 |
| network / server | 横幅「登录失败，请稍后重试。」 |

**失败后用户输入一律保留**——只有服务端消息被追加，从不清空表单。
**Password 只发送一次，不写日志、不回显**；成功后立即清空（`password.value = ''`），失败时保留以便用户修改。

## 8. 用户区组件

`AuthUserMenu.vue` 是**纯展示型**：接收 `user` prop，渲染 name / email 与「退出登录」，`emit('logout')`。

它**刻意没有挂进 `AdminLayout`**，原因写在组件注释里：全局 auth state 与访问守卫归后端任务所有。现在挂上去等于凭空造一个「当前用户」，而当前应用并没有可信来源——那正是本任务禁止的伪登录态。

组件注释给出了后端任务预期的接入方式：

```ts
const user = ref<AuthUser | null>(null)
onMounted(() => {
  authApi.getCurrentUser()
    .then((u) => { user.value = u })
    .catch(() => { /* 401 是正常的未登录态，交给守卫跳转 */ })
})
```

注意 `.catch(() => {})`：401 是「未登录」的正常答复，不是需要上报的错误；守卫负责跳转，header 留空即可。

## 9. 禁止生产 fallback

无 Auth API 时，UI 不会「自动视为已登录」：

- 无 mock user、无 fake user、无 development bypass。
- `getCurrentUser()` 要么返回服务端真实用户，要么 reject，由调用方决定未登录的含义。
- `NO_AUTH_USER = null` 且 `MaybeAuthUser` 与 `AuthUser` 类型分离，避免把「没人登录」误当合法用户传下去。
- Login 页在 API 缺失时显示错误态，不跳转、不假装成功。

## 10. 验收结果

| 项目 | 结果 |
| --- | --- |
| TypeScript | 零错误（`vue-tsc --noEmit`） |
| Vite build | 成功 |
| localStorage / sessionStorage | 无 |
| JWT / token persistence | 无 |
| password 打印 | 无（不 console、不回显） |
| mock fallback | 无 |
| 注册入口 | 无 |
| RBAC / 角色矩阵 | 无 |
| 后端文件变化 | 无（`git diff` 仅 4 个新增文件） |
| 前端测试框架 | 项目无 vitest/jest，按要求未安装重量依赖 |

## 11. 遗留与下一步

遗留：无功能性阻断。本分支尚未与任何 Auth API 联调（后端不存在），页面在浏览器中不会被路由到，因为 `routes/web.php` 未注册 `/auth/login`——这属于预期，本任务被明确禁止改路由。

下一步（Codex Auth Backend）：

1. 实现三个端点与 session 认证；`/api/auth/login` 失败返回 422 + `errors.email`，`/api/auth/me` 未登录返回 401。
2. 注册 `Auth/Login` Inertia 路由并把 `redirectTo` 传入。
3. 加未登录守卫（最小项目访问保护），401 时跳登录页。
4. 按第 8 节注释接入 `AuthUserMenu` 与全局 auth state。
5. 联调时重点核对：CSRF 首个 POST 是否通过、登录过期后的 419 表现、跨项目 scope 在未登录时是否被正确拦截。

本分支不合 main。
