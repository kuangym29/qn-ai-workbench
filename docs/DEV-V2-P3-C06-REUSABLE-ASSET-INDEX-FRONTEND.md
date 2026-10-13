# DEV · V2-P3-C06 人物 / 场景复用资产索引（轻量前端）

> 任务编号：V2-P3-C06-REUSABLE-ASSET-INDEX-FRONTEND
> 分支：`doubao/v2-reusable-asset-index-frontend`
> 基线：`codex/v2-foundation` @ `223e2ec3400364620cefd1e966dfafeed76b3d56`
> 范围：仅新增前端文件；不改 routes / backend / Foundation / 其他 C 任务 / main。
> 状态：**UI_IMPLEMENTED · WAITING_FOR_BACKEND**（未伪装真实联调）。

## 1. 产品定位

轻量复用索引，不是素材管理平台 / DAM / 图库 / 文件管理器。用户只回答三个问题：

1. 这个人物是否已有稳定母版？
2. 这个场景是否已有稳定母版？
3. 缺什么、哪些可复用？

**收录规则（冻结）**

- 人物只收长期重复出现的固定身份：爸爸 / 妈妈 / 姐姐 / 弟弟 / 爷爷 / 奶奶。
  路人、一次性角色、背景人物不建 Character。
- 场景只保存「稳定母版」：客厅 / 儿童房 / 厨房 / 办公室。
  早晨 / 夜晚 / 下雨 / 多一个杯子的客厅、人物位置变化等使用时变化，**不进入 Scene Master**。

**明确不做（本任务）**

上传、复杂编辑、文件夹、标签树、批量移动、版本树、制作状态机、生成 API。

## 2. 页面结构

- 顶部：标题「人物 / 场景复用索引」+ 轻量说明（稳定母版 / 不收录使用时变化）。
- 两个 Tab：人物（n）/ 场景（n）。
- 筛选：全部 / 已有 / 缺失（带数量）。
- 卡片：缩略图（辅助）、名称、状态（已有 / 缺失）、简短说明、最近使用、[查看]；
  缺失项克制显示琥珀色边框与「缺失」徽章，出现 [准备建立]。
- 空状态：筛选无结果 / 空列表（EmptyState）；加载 / 错误（LoadingState / ErrorState + 重试）。

## 3. Typed Contract（冻结，禁止扩展）

```ts
type ReusableAssetType = 'character' | 'scene';
type ReusableAssetStatus = 'existing' | 'missing'; // 仅两态

interface ReusableAssetItem {
  id: number | string;
  type: ReusableAssetType;
  name: string;
  status: ReusableAssetStatus;
  thumbnail_url: string | null;
  description: string | null;
  last_used_at: string | null;
}

interface ReusableAssetWorkspace {
  characters: ReusableAssetItem[];
  scenes: ReusableAssetItem[];
}
```

不扩展 prompt / model / seed / workflow / approval / owner / folder tree / technical metadata。

事件契约：

- 人物缺失 `[准备建立]` → `emit('prepareCharacter', item)`
- 场景缺失 `[准备建立]` → `emit('prepareScene', item)`
- 查看 → `viewCharacter` / `viewScene`（第一阶段均为占位，不调用 Work）

## 4. API Adapter（WAITING_FOR_BACKEND）

预留唯一端点（只读）：

```
GET /api/projects/{project}/reusable-assets
  → 200 { data: ReusableAssetWorkspace }
```

注意：只有 `project` 一个参数，无 item / branch。

- 正式状态：**UI_IMPLEMENTED / WAITING_FOR_BACKEND**；未改 routes、未伪装真实联调。
- 第一阶段没有上传 / 编辑 / 生成 API。
- 「准备建立」后续由 Work 主程序接入（建立母版的流程），本任务只保留 emit 契约。

## 5. Mock 隔离

- 生产：`assetIndexApi.ts`（createAssetIndexClient + http 单例）、`assetIndexCore.ts`、
  `useReusableAssetIndex.ts`，**0 引用 DevMock**（测试静态断言锁死）。
- DEV：`assetIndexDevMock.ts`（内存 client，仅 GET，记录调用 URL），
  只被 `ReusableAssetDevPreviewApp.vue` 与测试引用。
- 缩略图为内联 SVG data URI（无外链、无网络依赖）。

## 6. 与 ImageBatch 的边界

- ImageBatch 只消费「人物 / 场景是否已有」；C06 不保存页面、批次、Prompt、生成历史。
- 两个模块互不耦合，无共享状态。

## 7. 文件清单

新增：

```
resources/js/types/v2/reusableAssets/reusableAssets.ts
resources/js/composables/v2/reusableAssets/assetIndexCore.ts
resources/js/composables/v2/reusableAssets/assetIndexApi.ts
resources/js/composables/v2/reusableAssets/assetIndexDevMock.ts      （DEV ONLY）
resources/js/composables/v2/reusableAssets/useReusableAssetIndex.ts
resources/js/components/V2/ReusableAssets/ReusableAssetCard.vue
resources/js/components/V2/ReusableAssets/ReusableAssetIndexBody.vue
resources/js/components/V2/ReusableAssets/ReusableAssetDevPreviewApp.vue（DEV ONLY）
resources/js/pages/V2/ReusableAssets/ReusableAssetIndex.vue
resources/js/v2-reusable-assets-preview.ts                          （DEV ONLY）
v2-reusable-assets-preview.html                                     （DEV ONLY）
tests/frontend/v2/reusableAssets/assetIndexCore.test.ts
tests/frontend/v2/reusableAssets/assetIndexDevMock.test.ts
tests/frontend/v2/reusableAssets/useReusableAssetIndex.test.ts
tests/frontend/v2/reusableAssets/ts-extension-loader.mjs
tests/frontend/v2/reusableAssets/register-loader.mjs
```

## 8. 本地验证

```bash
# 浏览器预览（无需 Laravel / Inertia / 后端）
npm run dev
# 访问 http://localhost:5173/v2-reusable-assets-preview.html

# 类型检查 / 构建
npm run typecheck
npm run build

# 前端测试（node:test）
node --experimental-strip-types --import ./tests/frontend/v2/reusableAssets/register-loader.mjs \
  --test tests/frontend/v2/reusableAssets/
```

浏览器验收点：人物 Tab / 场景 Tab / 已有筛选 / 缺失筛选 / 空结果 / 长名称（小区楼下花园）/
无缩略图占位 / 准备建立按钮 / 353px 窄屏。

## 9. 待后端（WorkBuddy / Work 主程序）

1. 实现 `GET /api/projects/{project}/reusable-assets`，返回 `{ data: ReusableAssetWorkspace }`。
2. 正式 Inertia 路由与页面挂载（`V2/ReusableAssets/ReusableAssetIndex`，传 projectId）。
3. 「准备建立」后续流程（建立母版）与「查看」详情，由后续任务定义。
