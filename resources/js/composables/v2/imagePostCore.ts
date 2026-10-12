// V2 图片文章 AI 导入 —— 纯函数核心（不依赖 Vue，可被 node:test 直接测试）。
//
// 职责：
//   1. parseAiPaste()     把粘贴的 AI 内容解析为页面列表（支持 JSON 与宽松标记文本）。
//   2. buildImportPreview() 对「现有页面」与「解析结果」逐页逐字段比较，产出变更预览。
// 原则：
//   - 无法识别的内容进入 warnings，不静默丢弃，不臆造页面。
//   - 用户确认前绝不覆盖任何现有内容；本模块只计算，不写入。
import type {
  ImagePostPage,
  ImagePostPageField,
  ImagePostImportPreview,
  ImagePostParseResult,
} from '../../types/v2/imagePost';
import type { PageType } from '../../api/types';

// ---------------------------------------------------------------------------
// 页面类型解析
// ---------------------------------------------------------------------------

const PAGE_TYPE_ALIASES: Record<string, PageType> = {
  cover: 'cover',
  封面: 'cover',
  首页: 'cover',
  content: 'content',
  正文: 'content',
  内容: 'content',
  column_closing: 'column_closing',
  栏目收尾: 'column_closing',
  收尾: 'column_closing',
  收尾页: 'column_closing',
  fixed_back_cover: 'fixed_back_cover',
  固定封底: 'fixed_back_cover',
  封底: 'fixed_back_cover',
};

export function parsePageType(raw: string | null | undefined): PageType | null {
  if (raw == null) return null;
  const key = raw.trim().toLowerCase();
  return PAGE_TYPE_ALIASES[key] ?? null;
}

// ---------------------------------------------------------------------------
// 宽松标记文本解析
// ---------------------------------------------------------------------------

const FIELD_PREFIXES: Record<ImagePostPageField, string[]> = {
  narrative_role: ['叙事职责', '叙事', '职责'],
  new_fact: ['新事实', '本页发生的新事实', '新发生'],
  final_copy: ['最终文字', '最终文案', '文案', '文字', '正文'],
  visual_evidence: ['画面证据', '画面', '证据', '配图', '画面描述'],
  continuity: ['前后页承接', '承接', '前后承接', '衔接'],
  production_note: ['制作备注', '备注', '制作说明'],
};

const FIELD_KEYS: ImagePostPageField[] = [
  'narrative_role',
  'new_fact',
  'final_copy',
  'visual_evidence',
  'continuity',
  'production_note',
];

/** 行首是否以指定前缀开头（支持「叙事职责：」「叙事职责:」「叙事职责 -」等）。 */
function matchFieldPrefix(line: string): { field: ImagePostPageField; value: string } | null {
  const trimmed = line.trim();
  for (const field of FIELD_KEYS) {
    for (const prefix of FIELD_PREFIXES[field]) {
      // 前缀后紧跟分隔符才认为是字段行，避免「最终文字」误吃正文行。
      const rest = trimmed.startsWith(prefix) ? trimmed.slice(prefix.length).trim() : null;
      if (rest === null) continue;
      if (rest.startsWith(':') || rest.startsWith('：') || rest.startsWith('-') || rest.startsWith('—')) {
        return { field, value: rest.replace(/^[:：\-—]+\s*/, '').trim() };
      }
      // 「画面证据」这类双字前缀：允许紧贴内容（无分隔符）也识别。
      if (field === 'visual_evidence' && trimmed.length > prefix.length) {
        return { field, value: rest };
      }
    }
  }
  return null;
}

/** 解析「第N页」「P1」「Page 2」「1.」等页码标记。 */
function matchPageNo(line: string): number | null {
  const trimmed = line.trim();
  const m =
    trimmed.match(/^第\s*(\d+)\s*页/) ??
    trimmed.match(/^[Pp]age\s*(\d+)/) ??
    trimmed.match(/^[Pp]\s*(\d+)\b/) ??
    trimmed.match(/^\[(\d+)\]/) ??
    trimmed.match(/^(\d+)\s*[.、．]/);
  if (!m) return null;
  const n = Number(m[1]);
  return Number.isInteger(n) && n >= 1 ? n : null;
}

/** 页面类型行：「页面类型：封面」「类型：正文」等。 */
function matchPageTypeLine(line: string): PageType | null {
  const trimmed = line.trim();
  const m = trimmed.match(/^(?:页面类型|类型|type)\s*[:：\-—]?\s*(.+)$/i);
  if (!m) return null;
  return parsePageType(m[1]);
}

/**
 * 宽松标记文本 → 页面列表。
 *
 * 约定（文档见 docs/DEV-V2-P2-C01-IMAGE-POST-FRONTEND.md）：
 *   - 页面之间以「第N页」/「P1」/「Page N」/「[N]」等页码标记切分；
 *   - 字段行形如「叙事职责：xxx」「最终文字：xxx」；
 *   - 没有页码标记时整段视为一页，页码按出现顺序 1..N 分配并记 warning。
 */
export function parseMarkdownPages(raw: string): ImagePostParseResult {
  const warnings: string[] = [];
  const lines = raw.split(/\r?\n/);
  const pages: ImagePostPage[] = [];
  let current: ImagePostPage | null = null;
  let sawPageMarker = false;

  function flush(): void {
    if (current) pages.push(current);
    current = null;
  }

  function ensurePage(): ImagePostPage {
    if (current) return current;
    current = {
      page_no: pages.length + 1,
      page_type: 'content',
      narrative_role: null,
      new_fact: null,
      final_copy: null,
      visual_evidence: null,
      continuity: null,
      production_note: null,
    };
    return current;
  }

  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line) continue;

    const pageNo = matchPageNo(line);
    if (pageNo !== null) {
      sawPageMarker = true;
      flush();
      current = {
        page_no: pageNo,
        page_type: 'content',
        narrative_role: null,
        new_fact: null,
        final_copy: null,
        visual_evidence: null,
        continuity: null,
        production_note: null,
      };
      continue;
    }

    const typeHit = matchPageTypeLine(line);
    if (typeHit) {
      ensurePage().page_type = typeHit;
      continue;
    }

    const fieldHit = matchFieldPrefix(line);
    if (fieldHit) {
      ensurePage()[fieldHit.field] = fieldHit.value || null;
      continue;
    }

    // 无法识别的行：若当前页没有最终文字，作为最终文字候选；否则记 warning。
    const page = ensurePage();
    if (page.final_copy === null && page.new_fact === null && page.narrative_role === null) {
      page.final_copy = line;
    } else {
      warnings.push(`未识别行（已归入第 ${page.page_no} 页上下文）：${line.slice(0, 40)}${line.length > 40 ? '…' : ''}`);
    }
  }
  flush();

  if (pages.length === 0 && raw.trim()) {
    // 整段没有任何可识别结构：仍然给出一页，保留原文，提示人工处理。
    warnings.push('未能从粘贴内容识别页码结构，已作为第 1 页保留原文，请人工核对。');
    pages.push({
      page_no: 1,
      page_type: 'content',
      narrative_role: null,
      new_fact: null,
      final_copy: raw.trim(),
      visual_evidence: null,
      continuity: null,
      production_note: null,
    });
  }

  // 页码按升序稳定排序，避免 AI 输出乱序影响预览与保存顺序。
  pages.sort((a, b) => a.page_no - b.page_no);

  if (!sawPageMarker && pages.length > 0) {
    warnings.push('未识别到页码标记（第N页/P1 等），已按出现顺序分配页码，请核对。');
  }
  return { pages, warnings };
}

// ---------------------------------------------------------------------------
// JSON 解析
// ---------------------------------------------------------------------------

function normalizeJsonPage(raw: unknown, warnings: string[]): ImagePostPage | null {
  if (typeof raw !== 'object' || raw === null) {
    warnings.push('JSON 中存在非对象条目，已忽略。');
    return null;
  }
  const obj = raw as Record<string, unknown>;
  const pageNo = Number(obj.page_no ?? obj.pageNo);
  if (!Number.isInteger(pageNo) || pageNo < 1) {
    warnings.push('JSON 条目缺少合法 page_no，已忽略。');
    return null;
  }
  const type = parsePageType(typeof obj.page_type === 'string' ? obj.page_type : null);
  return {
    page_no: pageNo,
    page_type: type ?? 'content',
    narrative_role: typeof obj.narrative_role === 'string' ? obj.narrative_role : null,
    new_fact: typeof obj.new_fact === 'string' ? obj.new_fact : null,
    final_copy: typeof obj.final_copy === 'string' ? obj.final_copy : null,
    visual_evidence: typeof obj.visual_evidence === 'string' ? obj.visual_evidence : null,
    continuity: typeof obj.continuity === 'string' ? obj.continuity : null,
    production_note: typeof obj.production_note === 'string' ? obj.production_note : null,
  };
}

export function parseJsonPages(raw: string): ImagePostParseResult {
  const warnings: string[] = [];
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    warnings.push('JSON 解析失败，请检查粘贴内容是否为合法 JSON 数组。');
    return { pages: [], warnings };
  }
  if (!Array.isArray(parsed)) {
    warnings.push('JSON 顶层必须是数组（页面列表）。');
    return { pages: [], warnings };
  }
  const pages = parsed
    .map((item) => normalizeJsonPage(item, warnings))
    .filter((p): p is ImagePostPage => p !== null);
  pages.sort((a, b) => a.page_no - b.page_no);
  return { pages, warnings };
}

// ---------------------------------------------------------------------------
// 统一入口
// ---------------------------------------------------------------------------

/**
 * 将 AI 粘贴内容解析为页面列表。
 * 先尝试 JSON；非 JSON 或 JSON 解析无结果时回退到宽松标记文本解析。
 */
export function parseAiPaste(raw: string): ImagePostParseResult {
  const trimmed = raw.trim();
  if (!trimmed) return { pages: [], warnings: ['粘贴内容为空。'] };

  const looksJson = trimmed.startsWith('[') || trimmed.startsWith('{');
  if (looksJson) {
    const jsonResult = parseJsonPages(trimmed);
    // JSON 解析失败时回退文本解析，但保留 JSON 警告。
    if (jsonResult.pages.length > 0 || jsonResult.warnings.some((w) => w.includes('顶层'))) {
      return jsonResult;
    }
    const textResult = parseMarkdownPages(trimmed);
    return {
      pages: textResult.pages,
      warnings: [...jsonResult.warnings, ...textResult.warnings],
    };
  }
  return parseMarkdownPages(trimmed);
}

// ---------------------------------------------------------------------------
// 变更预览
// ---------------------------------------------------------------------------

function fieldEqual(a: ImagePostPage, b: ImagePostPage, field: ImagePostPageField): boolean {
  const av = a[field];
  const bv = b[field];
  if (av === bv) return true;
  if (av == null && bv == null) return true;
  return (av ?? '').trim() === (bv ?? '').trim();
}

/**
 * 现有页面 vs 解析结果 → 变更预览。
 *
 * 规则：
 *   - 页码存在于现有页面：逐业务字段比较，有差异记 modified；
 *   - 页码不存在：记 added；
 *   - unchanged = 现有页面中未被本次导入新增/修改的页
 *     （局部更新时，预览会明确显示「未变化 P1 / P2 / P5」，传达只影响某页）；
 *   - page_type 变化不进入 modified（field 只代表业务字段），以 warning 提示；
 *   - 全部按 page_no 升序输出，保证视觉稳定。
 */
export function buildImportPreview(
  current: ImagePostPage[],
  incoming: ImagePostPage[],
): ImagePostImportPreview {
  const byNo = new Map<number, ImagePostPage>();
  for (const page of current) byNo.set(page.page_no, page);

  const added: ImagePostPage[] = [];
  const modified: ImagePostImportPreview['modified'] = [];
  const warnings: string[] = [];
  const touched = new Set<number>();

  const fieldKeys: ImagePostPageField[] = [
    'narrative_role',
    'new_fact',
    'final_copy',
    'visual_evidence',
    'continuity',
    'production_note',
  ];

  for (const page of incoming) {
    const existing = byNo.get(page.page_no);
    if (!existing) {
      added.push(page);
      touched.add(page.page_no);
      continue;
    }
    touched.add(page.page_no);
    if (existing.page_type !== page.page_type) {
      warnings.push(
        `第 ${page.page_no} 页页面类型将从「${existing.page_type}」变为「${page.page_type}」（页面类型变更需人工确认）。`,
      );
    }
    let changed = false;
    for (const field of fieldKeys) {
      if (!fieldEqual(existing, page, field)) {
        changed = true;
        modified.push({
          page_no: page.page_no,
          field,
          before: existing[field],
          after: page[field],
        });
      }
    }
    if (!changed) {
      // incoming 命中但完全一致：不记 unchanged（它没有被触碰）。
    }
  }

  // unchanged = 现有页面中未被本次导入触碰的页（新增/修改都不算）。
  const unchanged = [...byNo.keys()]
    .filter((no) => !touched.has(no))
    .sort((a, b) => a - b);

  added.sort((a, b) => a.page_no - b.page_no);
  modified.sort((a, b) => a.page_no - b.page_no || a.field.localeCompare(b.field));

  return { added, modified, unchanged, warnings };
}

/** 预览是否包含任何实际变更（新增或修改）。 */
export function previewHasChanges(preview: ImagePostImportPreview): boolean {
  return preview.added.length > 0 || preview.modified.length > 0;
}
