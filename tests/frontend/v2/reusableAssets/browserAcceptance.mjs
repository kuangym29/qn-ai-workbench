// V2-P5-I02 —— 复用资产管理入口真实浏览器验收（真实 Laravel + 真实 Vite 构建产物 + 真实 MySQL/SQLite 持久化）。
//
// 覆盖口径：
//   S0  登录 → /projects → 用 UI「进入」建立 ProjectContext。
//   S1  复用资产页真实加载：人物 2 / 场景 2、业务中文状态、缺失卡有 [准备建立]。
//   S2  GET 契约冻结：200 且顶层恰好 {characters, scenes}（无 data 包裹）。
//   S3  新增缺失人物：弹层 → 保存 → 计数与卡片更新 → 刷新后仍在（真写库）→ last_used_at 仍为空。
//   S4  重复新增：弹层内可恢复中文错误，弹层保持打开；服务器未静默更新既有记录。
//   S5  新增场景：Tab 语义正确（新增场景）。
//   S6  「准备建立」→ 详情弹层：名称/类型/最近使用只读，状态/说明可写 → 保存 → 卡片与计数更新。
//   S7  「查看」→ 详情弹层（只读同一入口）。
//   S8  预览图地址加载失败 → 占位回退，不崩、不刷屏；显式 null 可清空。
//   S9  契约边界（真实 HTTP）：伪造字段 422、重复 422、非法枚举 422、不安全 scheme 422、
//      name/type 不可改 422、未知 / 跨项目 404、DELETE 405、跨项目 GET 404。
//   S10 Responsive：页面 + 弹层 × {353, 390, 1024}，scrollWidth <= clientWidth。
//   S11 console / network 精确记账：expected（主动触发 + 页面自发的 4xx）与 unexpected=0、5xx=0。
//
// 前置（由验收流程准备）：migrate:fresh + ReusableAssetBrowserSeeder + npm run build + php -S。
// 运行：node tests/frontend/v2/reusableAssets/browserAcceptance.mjs
// 环境变量：P5I02_BASE_URL（默认 http://127.0.0.1:8079）、CHROME_PATH

import { chromium } from 'playwright-core';

const BASE = process.env.P5I02_BASE_URL ?? 'http://127.0.0.1:8079';
const CHROME = process.env.CHROME_PATH ?? 'C:/Users/Administrator/AppData/Local/ms-playwright/chromium-1187/chrome-win/chrome.exe';

const PROJECT_NAME = 'P5-I02 复用资产验收';
const OTHER_PROJECT_NAME = 'P5-I02 跨项目隔离';
const EMAIL = 'p5i02@example.test';
const PASSWORD = 'password';

const results = [];
let failures = 0;

function check(step, ok, detail = '') {
  results.push({ step, ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail ? ' — ' + detail : ''}`);
}

/** 页面内同源请求（带 Session cookie + Laravel XSRF header）。 */
function inPage(method, url, body) {
  return async (page) =>
    page.evaluate(
      async ({ method, url, body }) => {
        const token = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
        const response = await fetch(url, {
          method,
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
          },
          body: body === undefined ? undefined : JSON.stringify(body),
        });
        return {
          status: response.status,
          json: await response.json().catch(() => null),
          text: await response.text().catch(() => ''),
        };
      },
      { method, url, body },
    );
}

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({ baseURL: BASE, viewport: { width: 1280, height: 900 } });
const page = await context.newPage();

// ── console / network 采集 ────────────────────────────────────────────────
const consoleErrors = [];
const expectedConsole = [];   // 命中下面「已知 expected」模式的 console error
const unexpectedConsole = []; // 其余（必须为 0）
const responses4xx = [];
const badRequests5xx = [];    // >=500（必须为 0）
const requestedUrls = [];

/**
 * 已知 expected：本验收**主动**制造的 4xx（伪造字段 / 重复 / 跨项目 / 405 探针）。
 * 这些是契约的一部分，不是缺陷。Chromium 会为它们打 resource 报错。
 */
const EXPECTED_CONSOLE_PATTERNS = [
  /Failed to load resource: the server responded with a status of 422/,
  /Failed to load resource: the server responded with a status of 404/,
  /Failed to load resource: the server responded with a status of 405/,
];

page.on('console', (message) => {
  if (message.type() !== 'error') return;
  const text = message.text();
  consoleErrors.push(text);
  if (EXPECTED_CONSOLE_PATTERNS.some((re) => re.test(text))) expectedConsole.push(text);
  else unexpectedConsole.push(text);
});
page.on('request', (request) => requestedUrls.push(`${request.method()} ${request.url()}`));
page.on('response', (response) => {
  const status = response.status();
  if (status >= 400 && status < 500) responses4xx.push(`${status} ${response.request().method()} ${new URL(response.url()).pathname}`);
  if (status >= 500) badRequests5xx.push(`${status} ${response.request().method()} ${response.url()}`);
});

/** 主动触发的契约探针（期望 4xx：422 / 404 / 405）。 */
async function probe(method, url, body) {
  return inPage(method, url, body)(page);
}

const DIALOG = '[data-v2-asset-dialog]';
const field = (name) => `${DIALOG} [data-v2-field="${name}"]`;
const card = (name) => page.locator('[data-v2-reusable-asset]').filter({ hasText: name }).first();

/**
 * 等待首屏真实取数完成。
 * 必须先等「加载中…」消失、再等 Tab 计数出现期望值 —— 否则会在 loading 期间
 * 读到 `人物（0）` 造成假失败（卡片可能已经渲染，但计数行还是初始态）。
 */
async function waitPageReady(expectedTabText) {
  await page.waitForSelector('[data-v2-asset-tabs]', { timeout: 30000 });
  await page.waitForFunction(() => !document.body.innerText.includes('加载中'), null, { timeout: 30000 });
  if (expectedTabText) {
    await page.waitForFunction(
      (want) => (document.querySelector('[data-v2-asset-tabs]')?.innerText ?? '').includes(want),
      expectedTabText,
      { timeout: 30000 },
    );
  }
  await page.waitForTimeout(200);
}

async function openDialogByCreate() {
  await page.click('[data-v2-asset-create]');
  await page.waitForSelector(DIALOG, { timeout: 15000 });
}

async function saveAndWaitClose() {
  await page.click(`${DIALOG} [data-v2-asset-save]`);
  await page.waitForSelector(DIALOG, { state: 'detached', timeout: 30000 });
  // 保存成功后 composable 会重新拉取正式 GET；等一次渲染落定再断言卡片 / 计数。
  await page.waitForTimeout(400);
}

/** 等待 Tab 计数行出现指定文本（保存后的重新取数不是同步的）。 */
async function waitTabs(text) {
  await page.waitForFunction(
    (want) => (document.querySelector('[data-v2-asset-tabs]')?.innerText ?? '').includes(want),
    text,
    { timeout: 30000 },
  ).catch(() => {});
}

/** 等待某张卡片出现指定文本。 */
async function waitCardText(name, text) {
  await page.waitForFunction(
    ({ name, text }) => {
      const cards = Array.from(document.querySelectorAll('[data-v2-reusable-asset]'));
      const hit = cards.find((c) => (c.innerText ?? '').includes(name));
      return Boolean(hit && (hit.innerText ?? '').includes(text));
    },
    { name, text },
    { timeout: 30000 },
  ).catch(() => {});
}

/** 读取某卡片的状态徽标文本与是否有 [准备建立]。 */
async function cardState(name) {
  const c = card(name);
  const text = await c.innerText();
  return {
    text,
    hasPrepare: (await c.locator('[data-v2-prepare-button]').count()) > 0,
    hasThumb: (await c.locator('[data-v2-asset-thumb]').count()) > 0,
    hasThumbPlaceholder: (await c.locator('[data-v2-asset-thumb-placeholder]').count()) > 0,
  };
}

let project = 0;
let otherProject = 0;

try {
  // ═══ S0. 登录 + 进入项目（ProjectContext）═══════════════════════════════
  await page.goto('/auth/login');
  await page.fill('input[type="email"]', EMAIL);
  await page.fill('input[type="password"]', PASSWORD);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/projects**', { timeout: 30000 });
  check('S0. login → /projects', page.url().includes('/projects'), page.url());

  // 用真实 UI「进入」目标项目（定位到项目名所在的卡片）。
  const targetCard = page
    .locator('div.rounded-lg', { has: page.locator('h2', { hasText: PROJECT_NAME }) })
    .first();
  await targetCard.waitFor({ state: 'visible', timeout: 30000 });
  await targetCard.getByRole('button', { name: '进入' }).click();
  await page.waitForURL('**/columns', { timeout: 30000 });
  project = Number(page.url().match(/projects\/(\d+)/)[1]);
  check('S0. UI「进入」建立 ProjectContext', Number.isInteger(project) && project > 0, page.url());

  // 跨项目验收目标 id（不经 UI 进入，只按项目名从真实 API 取 id）。
  const allProjects = await probe('GET', '/api/projects', undefined);
  const list = allProjects.json?.data ?? allProjects.json ?? [];
  const other = (Array.isArray(list) ? list : []).find((p) => p.name === OTHER_PROJECT_NAME);
  otherProject = Number(other?.id ?? 0);
  check('S0. 第二个项目存在且 id 可解析（跨项目隔离对象）', otherProject > 0 && otherProject !== project, `other=${otherProject}`);

  const assetsUrl = `/api/projects/${project}/reusable-assets`;
  const pageUrl = `/projects/${project}/reusable-assets`;

  // ═══ S1. 页面真实加载 ══════════════════════════════════════════════════
  await page.goto(pageUrl);
  await waitPageReady('人物（2）');

  const subjectGets = requestedUrls.filter((u) => u.includes(`/api/projects/${project}/reusable-assets`) && u.startsWith('GET'));
  check('S1. 页面经真实 GET API 取数（0 Production Mock 兜底）', subjectGets.length >= 1, `${subjectGets.length} 次`);

  const tabsText = await page.locator('[data-v2-asset-tabs]').innerText();
  check('S1. 人物 Tab 显示真实计数 2', tabsText.includes('人物（2）'), tabsText.replace(/\n/g, ' | '));

  check('S1. 人物卡片数 = 2', (await page.locator('[data-v2-reusable-asset]').count()) === 2);

  const mother = await cardState('妈妈');
  const brother = await cardState('弟弟');
  check('S1. 「妈妈」= 已有（无 [准备建立]）', mother.text.includes('已有') && !mother.hasPrepare, mother.text.replace(/\n/g, ' | '));
  check('S1. 「弟弟」= 缺失（有 [准备建立]）', brother.text.includes('缺失') && brother.hasPrepare, brother.text.replace(/\n/g, ' | '));

  const bodyText = await page.locator('body').innerText();
  check('S1. 状态 UI 不暴露 existing / missing 字面', !/existing|missing/.test(bodyText));

  // ═══ S2. GET 契约冻结 ══════════════════════════════════════════════════
  const getRes = await probe('GET', assetsUrl, undefined);
  const keys = getRes.json && typeof getRes.json === 'object' ? Object.keys(getRes.json).sort() : [];
  check('S2. GET 200', getRes.status === 200, `${getRes.status}`);
  check('S2. GET 顶层恰好 {characters, scenes}（无 data 包裹）', JSON.stringify(keys) === JSON.stringify(['characters', 'scenes']), JSON.stringify(keys));

  // ═══ S3. 新增缺失人物 ══════════════════════════════════════════════════
  {
    await openDialogByCreate();
    const title = await page.locator(`${DIALOG} h2`).innerText();
    check('S3. 弹层标题「新增人物」', title.trim() === '新增人物', title);

    const nameEditable = (await page.getAttribute(field('name'), 'readonly')) === null;
    check('S3. create 模式名称可编辑', nameEditable);

    await page.fill(field('name'), '叔叔');
    await page.selectOption(field('status'), 'missing');
    await saveAndWaitClose();

    const after = await probe('GET', assetsUrl, undefined);
    const uncle = (after.json?.characters ?? []).find((c) => c.name === '叔叔');
    check('S3. 新增后 GET 出现「叔叔」且 status=missing', Boolean(uncle) && uncle.status === 'missing', JSON.stringify(uncle));
    check('S3. 新增条目 last_used_at 未被前端写入（系统字段）', uncle ? uncle.last_used_at === null : false, JSON.stringify(uncle?.last_used_at));
    await waitTabs('人物（3）');
    check('S3. 保存后人物计数 → 3', (await page.locator('[data-v2-asset-tabs]').innerText()).includes('人物（3）'));

    // 官方 GET 重新加载（真实刷新页面）。
    await page.reload();
    await waitPageReady('人物（3）');
    check('S3. 刷新后「叔叔」仍在（真写库）', (await card('叔叔').count()) === 1);
    check('S3. 刷新后「叔叔」仍是缺失卡（有 [准备建立]）', (await cardState('叔叔')).hasPrepare);
  }

  // ═══ S4. 重复新增 → 可恢复错误，不静默更新 ═════════════════════════════
  {
    await openDialogByCreate();
    await page.fill(field('name'), '叔叔');
    await page.click(`${DIALOG} [data-v2-asset-save]`);
    await page.waitForSelector(`${DIALOG} [data-v2-asset-error]`, { timeout: 30000 });

    const errText = await page.locator(`${DIALOG} [data-v2-asset-error]`).innerText();
    check('S4. 重复新增显示可读中文错误', errText.includes('同名人物已存在'), errText.trim());
    check('S4. 错误后弹层保持打开（可恢复，不关闭）', (await page.locator(DIALOG).count()) === 1);

    const rows = await probe('GET', assetsUrl, undefined);
    const uncles = (rows.json?.characters ?? []).filter((c) => c.name === '叔叔');
    check('S4. 服务器未静默更新既有记录（仍恰好 1 条）', uncles.length === 1, `${uncles.length} 条`);

    await page.click(`${DIALOG} [data-v2-asset-dialog-close]`);
    await page.waitForSelector(DIALOG, { state: 'detached', timeout: 15000 });
    check('S4. 关闭弹层后页面恢复可用', (await page.locator('[data-v2-asset-tabs]').count()) === 1);
  }

  // ═══ S5. 场景 Tab + 新增场景 ═══════════════════════════════════════════
  {
    await page.locator('[data-v2-asset-tabs] button', { hasText: '场景' }).click();
    await page.waitForFunction(
      () => (document.querySelector('[data-v2-asset-create]')?.textContent ?? '').includes('新增场景'),
      null,
      { timeout: 15000 },
    );
    check('S5. 场景 Tab 计数 = 2', (await page.locator('[data-v2-asset-tabs]').innerText()).includes('场景（2）'));
    check('S5. 切到场景后新增按钮语义为「新增场景」', (await page.locator('[data-v2-asset-create]').innerText()).includes('新增场景'));

    await openDialogByCreate();
    await page.fill(field('name'), '楼道');
    await saveAndWaitClose();
    await waitTabs('场景（3）');
    check('S5. 新增场景后计数 → 3', (await page.locator('[data-v2-asset-tabs]').innerText()).includes('场景（3）'));
    check('S5. 场景列表出现「楼道」', (await card('楼道').count()) === 1);
  }

  // ═══ S6. 「准备建立」→ 详情编辑 → 保存 ═════════════════════════════════
  {
    await page.locator('[data-v2-asset-tabs] button', { hasText: '人物' }).click();
    await page.waitForFunction(
      () => (document.querySelector('[data-v2-asset-create]')?.textContent ?? '').includes('新增人物'),
      null,
      { timeout: 15000 },
    );

    await card('弟弟').locator('[data-v2-prepare-button]').click();
    await page.waitForSelector(DIALOG, { timeout: 15000 });

    check('S6. 弹层标题「人物详情」', (await page.locator(`${DIALOG} h2`).innerText()).trim() === '人物详情');
    check('S6. 名称只读（稳定身份）', (await page.getAttribute(field('name'), 'readonly')) !== null);
    check('S6. 类型以只读文本呈现', (await page.locator(field('type')).innerText()).trim() === '人物');
    check('S6. 最近使用只读且为「未使用」', (await page.locator(field('last_used_at')).innerText()).trim() === '未使用');

    const statusOptions = await page.locator(`${field('status')} option`).allInnerTexts();
    check(
      'S6. 状态下拉只出现业务中文',
      JSON.stringify(statusOptions.map((s) => s.trim())) === JSON.stringify(['已有稳定母版', '待建立']),
      JSON.stringify(statusOptions),
    );

    await page.selectOption(field('status'), 'existing');
    await page.fill(field('description'), '幼儿男孩，学步期；已建立统一形象参考。');
    await saveAndWaitClose();
    await waitCardText('弟弟', '已建立统一形象参考');

    const state = await cardState('弟弟');
    check('S6. 保存后卡片转为「已有」', state.text.includes('已有'), state.text.replace(/\n/g, ' | '));
    check('S6. 保存后 [准备建立] 消失', !state.hasPrepare);
    check('S6. 保存后卡片显示新说明', state.text.includes('已建立统一形象参考'));

    const rows = await probe('GET', assetsUrl, undefined);
    const di = (rows.json?.characters ?? []).find((c) => c.name === '弟弟');
    check('S6. 服务器已持久化 status=existing', di?.status === 'existing', JSON.stringify(di?.status));
    check('S6. PATCH 未触碰 last_used_at（仍为 null）', di?.last_used_at === null, JSON.stringify(di?.last_used_at));

    // 缺失筛选下不再出现「弟弟」。
    await page.locator('[data-v2-asset-filters] button', { hasText: '缺失' }).click();
    await page.waitForTimeout(350);
    const missingCount = await page.locator('[data-v2-reusable-asset]').count();
    check('S6. 缺失筛选下不再包含「弟弟」', (await card('弟弟').count()) === 0, `缺失卡共 ${missingCount} 张`);
    await page.locator('[data-v2-asset-filters] button', { hasText: '全部' }).click();
    await page.waitForTimeout(250);
  }

  // ═══ S7. 「查看」→ 详情弹层（同一只读入口）═════════════════════════════
  {
    await card('妈妈').getByRole('button', { name: '查看' }).click();
    await page.waitForSelector(DIALOG, { timeout: 15000 });
    check('S7. 「查看」打开人物详情', (await page.locator(`${DIALOG} h2`).innerText()).trim() === '人物详情');
    check('S7. 详情中名称为「妈妈」且只读', (await page.inputValue(field('name'))) === '妈妈' && (await page.getAttribute(field('name'), 'readonly')) !== null);
    check('S7. 详情中已回填既有说明', (await page.inputValue(field('description'))).includes('稳定主视觉'));
    await page.click(`${DIALOG} [data-v2-asset-dialog-close]`);
    await page.waitForSelector(DIALOG, { state: 'detached', timeout: 15000 });
  }

  // ═══ S8. 预览图加载失败 → 占位；显式 null 可清空 ═══════════════════════
  {
    const target = await probe('GET', assetsUrl, undefined);
    const motherRow = (target.json?.characters ?? []).find((c) => c.name === '妈妈');
    check('S8. 可读到「妈妈」id 用于预览图验收', Boolean(motherRow?.id), JSON.stringify(motherRow?.id));

    // 指向一个「HTTP 200 但不是图片」的真实同源地址 → 必然触发 img error 回退。
    const brokenThumb = `/api/projects/${project}/reusable-assets`;
    const patched = await probe('PATCH', `${assetsUrl}/${motherRow.id}`, { thumbnail_url: brokenThumb });
    check('S8. PATCH 预览图地址 200', patched.status === 200, `${patched.status}`);
    check('S8. GET 已存下该地址', (await probe('GET', assetsUrl, undefined)).json?.characters?.find((c) => c.name === '妈妈')?.thumbnail_url === brokenThumb);

    const beforeConsole = consoleErrors.length;
    await page.reload();
    await waitPageReady('人物（3）');
    await page.waitForFunction(
      () => document.querySelectorAll('[data-v2-asset-thumb-placeholder]').length >= 1,
      null,
      { timeout: 15000 },
    ).catch(() => {});
    await page.waitForTimeout(600);

    const motherAfter = await cardState('妈妈');
    check('S8. 预览图加载失败 → 显示占位', motherAfter.hasThumbPlaceholder, JSON.stringify(motherAfter));
    check('S8. 失败图不残留 img 元素（无破图）', !motherAfter.hasThumb);
    check('S8. 卡片未崩溃（页面仍可交互）', (await page.locator('[data-v2-asset-tabs]').count()) === 1);
    check('S8. 缩略图故障未产生新的 console error（不刷屏）', consoleErrors.length - beforeConsole === 0, consoleErrors.slice(beforeConsole).slice(0, 3).join(' | '));

    // 显式 null 清空。
    const cleared = await probe('PATCH', `${assetsUrl}/${motherRow.id}`, { thumbnail_url: null });
    check('S8. 显式 null 清空预览图 200', cleared.status === 200 && cleared.json?.thumbnail_url === null, `${cleared.status}`);
  }

  // ═══ S9. 契约边界（真实 HTTP）══════════════════════════════════════════
  {
    const rows = await probe('GET', assetsUrl, undefined);
    const someId = rows.json?.characters?.[0]?.id;

    const forged = [
      ['id', { type: 'character', name: '伪造ID', status: 'missing', id: 999 }],
      ['project_id', { type: 'character', name: '伪造项目', status: 'missing', project_id: 999 }],
      ['last_used_at', { type: 'character', name: '伪造时间', status: 'missing', last_used_at: '2026-10-01 00:00:00' }],
      ['created_at', { type: 'character', name: '伪造创建', status: 'missing', created_at: '2026-10-01 00:00:00' }],
      ['updated_at', { type: 'character', name: '伪造更新', status: 'missing', updated_at: '2026-10-01 00:00:00' }],
    ];
    for (const [label, payload] of forged) {
      const res = await probe('POST', assetsUrl, payload);
      check(`S9. POST 伪造 ${label} → 422（绝不静默忽略）`, res.status === 422, `${res.status}`);
    }

    const dup = await probe('POST', assetsUrl, { type: 'character', name: '叔叔', status: 'missing' });
    check('S9. POST 重复 (type,name) → 422', dup.status === 422 && JSON.stringify(dup.json?.errors ?? {}).includes('同名人物已存在'), `${dup.status}`);

    const crossType = await probe('POST', assetsUrl, { type: 'scene', name: '叔叔', status: 'missing' });
    check('S9. POST 同名不同类型 → 201（同名跨类型允许）', crossType.status === 201, `${crossType.status}`);

    const badType = await probe('POST', assetsUrl, { type: 'prop', name: '非法类型', status: 'missing' });
    check('S9. POST 非法 type → 422', badType.status === 422, `${badType.status}`);

    const badStatus = await probe('POST', assetsUrl, { type: 'character', name: '非法状态', status: 'ready' });
    check('S9. POST 非法 status → 422', badStatus.status === 422, `${badStatus.status}`);

    const noName = await probe('POST', assetsUrl, { type: 'character', name: '   ', status: 'missing' });
    check('S9. POST 纯空白 name → 422', noName.status === 422, `${noName.status}`);

    const unsafe = await probe('POST', assetsUrl, { type: 'character', name: '不安全预览', status: 'missing', thumbnail_url: 'javascript:alert(1)' });
    check('S9. POST 不安全 thumbnail scheme → 422', unsafe.status === 422, `${unsafe.status}`);

    const dataUri = await probe('POST', assetsUrl, { type: 'character', name: 'data预览', status: 'missing', thumbnail_url: 'data:image/png;base64,AAAA' });
    check('S9. POST data: 预览图 → 422（本版不做上传 / 内联）', dataUri.status === 422, `${dataUri.status}`);

    const patchName = await probe('PATCH', `${assetsUrl}/${someId}`, { name: '改名字' });
    check('S9. PATCH name → 422（name 是稳定身份）', patchName.status === 422, `${patchName.status}`);

    const patchType = await probe('PATCH', `${assetsUrl}/${someId}`, { type: 'scene' });
    check('S9. PATCH type → 422（type 是稳定身份）', patchType.status === 422, `${patchType.status}`);

    const patchLastUsed = await probe('PATCH', `${assetsUrl}/${someId}`, { last_used_at: '2026-10-01 00:00:00' });
    check('S9. PATCH last_used_at → 422（系统字段）', patchLastUsed.status === 422, `${patchLastUsed.status}`);

    const patchBadStatus = await probe('PATCH', `${assetsUrl}/${someId}`, { status: 'ready' });
    check('S9. PATCH 非法 status → 422', patchBadStatus.status === 422, `${patchBadStatus.status}`);

    const patchUnknown = await probe('PATCH', `${assetsUrl}/99999999`, { status: 'existing' });
    check('S9. PATCH 未知 asset → 404', patchUnknown.status === 404, `${patchUnknown.status}`);

    const removed = await probe('DELETE', `${assetsUrl}/${someId}`, undefined);
    check('S9. DELETE → 405（本版不提供删除）', removed.status === 405, `${removed.status}`);

    const crossProject = await probe('GET', `/api/projects/${otherProject}/reusable-assets`, undefined);
    check('S9. 跨 Project GET → 404（会话 Project 不匹配）', crossProject.status === 404, `${crossProject.status}`);

    const crossProjectPatch = await probe('PATCH', `/api/projects/${otherProject}/reusable-assets/${someId}`, { status: 'existing' });
    check('S9. 跨 Project PATCH → 404', crossProjectPatch.status === 404, `${crossProjectPatch.status}`);

    const foreignAsset = await probe('PATCH', `${assetsUrl}/99999998`, { status: 'existing' });
    check('S9. 同 Project 内未知 asset PATCH → 404（按 Project 作用域解析）', foreignAsset.status === 404, `${foreignAsset.status}`);
  }

  // ═══ S10. Responsive：页面 + 弹层 × 3 宽度 ═════════════════════════════
  {
    await page.goto(pageUrl);
    await waitPageReady('人物（3）');

    const measure = async (label) => {
      for (const width of [353, 390, 1024]) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(450);
        const m = await page.evaluate(() => ({
          scroll: document.documentElement.scrollWidth,
          client: document.documentElement.clientWidth,
        }));
        check(`S10. ${label} @${width}px: scrollWidth <= clientWidth`, m.scroll <= m.client + 1, `scroll=${m.scroll} client=${m.client}`);
      }
      await page.setViewportSize({ width: 1280, height: 900 });
    };

    await measure('复用资产页');

    // 弹层同样在 353 窄屏下不得横向溢出。
    await openDialogByCreate();
    await measure('新增弹层');
    await page.click(`${DIALOG} [data-v2-asset-dialog-close]`);
    await page.waitForSelector(DIALOG, { state: 'detached', timeout: 15000 });
  }

  // ═══ S11. console / network 记账 ═══════════════════════════════════════
  check('S11. console: 0 unexpected error', unexpectedConsole.length === 0, unexpectedConsole.slice(0, 3).join(' | '));
  check('S11. network: 0 个 5xx', badRequests5xx.length === 0, badRequests5xx.slice(0, 3).join(' | '));

  const probe4xx = responses4xx.filter((r) => /POST|PATCH|DELETE/.test(r));
  check('S11. 主动契约探针的 4xx 全部被计入 expected', expectedConsole.length >= probe4xx.length, `expected=${expectedConsole.length} probe4xx=${probe4xx.length}`);

  console.log(`\n[accounting] consoleErrors=${consoleErrors.length} expected=${expectedConsole.length} unexpected=${unexpectedConsole.length} 5xx=${badRequests5xx.length}`);
  console.log(`[accounting] 4xx responses=${responses4xx.length}`);
  for (const r of responses4xx) console.log(`[accounting]   4xx ${r}`);
  if (unexpectedConsole.length) console.log(`[accounting] unexpected console: ${unexpectedConsole.join(' ; ')}`);
} catch (err) {
  check('FATAL', false, String(err && err.stack ? err.stack : err).slice(0, 1200));
} finally {
  await browser.close();
}

const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} browser checks passed`);
process.exit(failures === 0 ? 0 : 1);
