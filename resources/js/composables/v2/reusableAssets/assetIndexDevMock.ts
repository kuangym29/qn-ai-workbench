// V2 人物 / 场景复用资产索引 —— DEV Mock（DEV ONLY，独立于正式生产模块）。
//
// 正式模块（assetIndexApi / assetIndexCore / useReusableAssetIndex）绝不引用本文件。
// 本文件只被：
//   - ReusableAssetDevPreviewApp.vue（浏览器开发预览）
//   - assetIndexDevMock.test.ts / useReusableAssetIndex.test.ts（测试）
// 引用。
//
// 提供：
//   1. buildMockWorkspace()：固定示例索引（人物 6 固定身份 / 场景 5 稳定母版），
//      覆盖缺失、无缩略图、从未使用、长名称等边界。
//   2. createMockAssetIndexClient()：内存实现 AssetIndexClient（GET + POST + PATCH），
//      并记录调用（calls），便于断言 URL。
//   缩略图用内联 SVG data URI（无外链、无网络依赖）。
import {
  createAssetIndexClient,
  type AssetIndexClient,
  type ReusableAssetCreatePayload,
  type ReusableAssetUpdatePayload,
} from './assetIndexApi';
import type {
  ReusableAssetItem,
  ReusableAssetStatus,
  ReusableAssetType,
  ReusableAssetWorkspace,
} from '../../../types/v2/reusableAssets/reusableAssets';

/** 生成带首字的内联 SVG 缩略图（DEV 占位，不依赖任何外部图片）。 */
function thumb(label: string, fill: string): string {
  const ch = label.slice(0, 1);
  const svg =
    `<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">` +
    `<rect width="96" height="96" rx="12" fill="${fill}"/>` +
    `<text x="48" y="60" font-size="40" text-anchor="middle" fill="#475569" font-family="sans-serif">${ch}</text>` +
    `</svg>`;
  return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
}

function character(
  id: number,
  name: string,
  status: ReusableAssetItem['status'],
  description: string | null,
  lastUsedAt: string | null,
  thumbnail: string | null,
): ReusableAssetItem {
  return { id, type: 'character', name, status, thumbnail_url: thumbnail, description, last_used_at: lastUsedAt };
}

function scene(
  id: number,
  name: string,
  status: ReusableAssetItem['status'],
  description: string | null,
  lastUsedAt: string | null,
  thumbnail: string | null,
): ReusableAssetItem {
  return { id, type: 'scene', name, status, thumbnail_url: thumbnail, description, last_used_at: lastUsedAt };
}

export function buildMockWorkspace(): ReusableAssetWorkspace {
  return {
    characters: [
      character(1, '妈妈', 'existing', '30 岁左右，齐肩短发，常穿浅色家居服；温柔耐心的家庭主视觉。', '2026-09-28T10:12:00+08:00', thumb('妈', '#fde68a')),
      character(2, '爸爸', 'existing', '30 岁左右，短发，戴细框眼镜；日常衬衫 / T 恤。', '2026-09-25T20:40:00+08:00', thumb('爸', '#bfdbfe')),
      character(3, '姐姐', 'existing', '学龄前女孩，扎两个小辫，常穿明亮色上衣。', '2026-09-29T08:05:00+08:00', thumb('姐', '#fbcfe8')),
      // 缺失：无缩略图、从未使用
      character(4, '弟弟', 'missing', '幼儿男孩，学步期；尚无稳定参考，需要先建立统一形象。', null, null),
      character(5, '爷爷', 'existing', '约 63 岁，灰白短发，身形清瘦；常穿深色夹克。', '2026-09-15T09:30:00+08:00', thumb('爷', '#ddd6fe')),
      // 缺失：无缩略图
      character(6, '奶奶', 'missing', '约 61 岁，和蔼微胖；需要建立固定发型与服装参考。', '2026-08-20T18:00:00+08:00', null),
    ],
    scenes: [
      scene(11, '客厅', 'existing', '暖色调现代家庭客厅，布艺沙发 + 落地窗；固定机位基础构图。', '2026-09-29T08:10:00+08:00', thumb('客', '#bbf7d0')),
      scene(12, '儿童房', 'existing', '浅色木家具，靠墙小书桌与玩具收纳架；柔和自然光。', '2026-09-26T19:20:00+08:00', thumb('童', '#a7f3d0')),
      // 缺失：无缩略图
      scene(13, '厨房', 'missing', '开放式小厨房，L 型台面；需要建立稳定视角与光线参考。', null, null),
      // 长名称 + 已有但无缩略图（边界）
      scene(14, '小区楼下花园', 'existing', '绿植步道与儿童活动区；固定取位为长椅一侧三分之一构图。', '2026-09-10T17:45:00+08:00', null),
      // 已有但从未使用
      scene(15, '办公室', 'existing', '白色办公桌 + 双显示器；工位固定机位，背景为百叶窗。', null, thumb('办', '#e2e8f0')),
    ],
  };
}

export interface MockAssetCall {
  method: 'GET' | 'POST' | 'PATCH';
  url: string;
}

export interface MockAssetIndexClient {
  client: AssetIndexClient;
  calls: MockAssetCall[];
  workspace: ReusableAssetWorkspace;
}

/** 创建内存 Mock client（每次调用独立 store，便于测试隔离）。 */
export function createMockAssetIndexClient(initial?: ReusableAssetWorkspace): MockAssetIndexClient {
  const workspace: ReusableAssetWorkspace = structuredClone(initial ?? buildMockWorkspace());
  const calls: MockAssetCall[] = [];

  const listOf = (type: ReusableAssetType): ReusableAssetItem[] =>
    type === 'character' ? workspace.characters : workspace.scenes;

  const nextId = (): number =>
    Math.max(0, ...workspace.characters.map((i) => Number(i.id)), ...workspace.scenes.map((i) => Number(i.id))) + 1;

  const client = createAssetIndexClient({
    async get(url: string) {
      calls.push({ method: 'GET', url });
      // 模拟正式后端：HTTP 响应体即 workspace 本体（无 data 包裹），
      // 与 B06 ReusableAssetController 的 response()->json(workspace) 对齐。
      return { data: structuredClone(workspace) };
    },

    async post(url: string, body: unknown) {
      calls.push({ method: 'POST', url });
      const payload = body as ReusableAssetCreatePayload;
      const list = listOf(payload.type);

      // 模拟后端同名冲突（(type, name) 唯一）。
      if (list.some((item) => item.name === payload.name)) {
        throw new Error(payload.type === 'character' ? '同名人物已存在。' : '同名场景已存在。');
      }

      const created: ReusableAssetItem = {
        id: nextId(),
        type: payload.type,
        name: payload.name,
        status: payload.status,
        thumbnail_url: payload.thumbnail_url ?? null,
        description: payload.description ?? null,
        last_used_at: null, // 系统字段：create 不写
      };
      list.push(created);

      return { data: structuredClone(created) };
    },

    async patch(url: string, body: unknown) {
      calls.push({ method: 'PATCH', url });
      const id = Number(url.split('/').pop());
      const target = [...workspace.characters, ...workspace.scenes].find((i) => Number(i.id) === id);

      if (!target) throw new Error('资产不存在。');

      const payload = body as ReusableAssetUpdatePayload;
      if (payload.status !== undefined) target.status = payload.status as ReusableAssetStatus;
      if (payload.thumbnail_url !== undefined) target.thumbnail_url = payload.thumbnail_url || null;
      if (payload.description !== undefined) target.description = payload.description || null;

      return { data: structuredClone(target) };
    },
  });

  return { client, calls, workspace };
}
