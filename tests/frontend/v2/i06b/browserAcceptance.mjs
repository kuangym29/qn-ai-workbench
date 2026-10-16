// V2-P3-I06B —— 真实浏览器验收（真实 Laravel + 真实 Vite 构建产物）。
//
// 覆盖：
//   A. Reusable Asset 正式页面（人物 / 场景复用索引）—— 标题 / Tab / 筛选 / 卡片状态
//   B. ImageBatch 正式页面 —— 3 单元 / 内容标题 / assets 空状态
//   C. 353px / 390px —— 全页无横向滚动
//   D. Mobile Drawer（仅在使用 AdminLayout 的 ImageBatch 页面验证汉堡按钮 / 抽屉 / 背景遮罩）
//   E. console 0 意外错误 / 无意外 5xx
//   F. ImageBatch 全流程 —— Draft Save / full / trial / partial / copy-task / history / keep / regenerate
//
// 说明：ReusableAssetIndex.vue 未挂 AdminLayout（I06A 既有设计，不在 I06B 范围），
//       故 Mobile Drawer 在同样基于 AdminLayout 的 ImageBatch 页面验收。
//
// 前置（由 bootstrap 准备）：migrate:fresh + I06bBrowserSeeder + npm run build + php -S。
// 运行：node tests/frontend/v2/i06b/browserAcceptance.mjs
// 环境变量：I06B_BASE_URL（默认 http://127.0.0.1:8077）、CHROME_PATH

import { chromium } from 'playwright-core';

const BASE = process.env.I06B_BASE_URL ?? 'http://127.0.0.1:8077';
const CHROME = process.env.CHROME_PATH ?? 'C:/Users/Administrator/AppData/Local/ms-playwright/chromium-1187/chrome-win/chrome.exe';

const results = [];
let failures = 0;

function check(step, ok, detail = '') {
  results.push({ step, ok, detail });
  if (!ok) failures++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${step}${detail ? ' — ' + detail : ''}`);
}

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
        return { status: response.status, json: await response.json().catch(() => null) };
      },
      { method, url, body },
    );
}

// 全页无横向滚动（H01 后整个 AdminLayout 不应产生横向溢出）。
async function assertNoHScroll(page, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.waitForTimeout(450);
  const m = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  check(`C. 全页无横向滚动 @${width}px`, m.scrollWidth <= m.clientWidth + 1, `scroll=${m.scrollWidth} client=${m.clientWidth}`);
  return m;
}

// Mobile Drawer：仅在使用 AdminLayout 的页面生效（ImageBatch）。
async function assertDrawer(page, width) {
  await page.setViewportSize({ width, height: 900 });
  await page.waitForTimeout(450);
  const m = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  check(`C. 全页无横向滚动 @${width}px（ImageBatch）`, m.scrollWidth <= m.clientWidth + 1, `scroll=${m.scrollWidth} client=${m.clientWidth}`);

  const hb = page.getByRole('button', { name: '切换导航菜单' });
  check(`D. 汉堡按钮可见 @${width}px`, await hb.isVisible(), '');
  await hb.click();
  await page.waitForTimeout(400);
  const backdropVisible = await page.locator('div.fixed.inset-0.z-30').isVisible().catch(() => false);
  check(`D. 抽屉打开显示背景遮罩 @${width}px`, backdropVisible, '');
  const mOpen = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  check(`D. 抽屉打开后仍无横向滚动 @${width}px`, mOpen.scrollWidth <= mOpen.clientWidth + 1, `scroll=${mOpen.scrollWidth} client=${mOpen.clientWidth}`);
  await page.locator('div.fixed.inset-0.z-30').dispatchEvent('click').catch(() => undefined);
  await page.waitForTimeout(400);
  const mClosed = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
  check(`D. 关闭抽屉后无横向滚动 @${width}px`, mClosed.scrollWidth <= mClosed.clientWidth + 1, `scroll=${mClosed.scrollWidth} client=${mClosed.clientWidth}`);
}

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({
  baseURL: BASE,
  viewport: { width: 1280, height: 900 },
  permissions: ['clipboard-read', 'clipboard-write'],
});
const page = await context.newPage();

const consoleErrors = [];
const badRequests = [];
const injectedConsole = [];
const injectedRequests = [];
let injectingFailure = false;

page.on('console', (message) => {
  if (message.type() !== 'error') return;
  (injectingFailure ? injectedConsole : consoleErrors).push(message.text());
});
page.on('response', (response) => {
  if (response.status() < 500) return;
  const line = `${response.status()} ${response.request().method()} ${response.url()}`;
  (injectingFailure ? injectedRequests : badRequests).push(line);
});

async function mutateSource(scope, pageNo, finalCopy) {
  const current = await inPage('GET', `/api/projects/${scope.project}/items/${scope.item}/branches/${scope.branch}/image-post`)(page);
  const pages = current.json.pages.map((p) => ({
    page_no: p.page_no,
    page_type: p.page_type,
    narrative_role: p.narrative_role ?? null,
    new_fact: p.new_fact ?? null,
    final_copy: p.page_no === pageNo ? finalCopy : p.final_copy,
    visual_evidence: p.visual_evidence ?? null,
    continuity: p.continuity ?? null,
    production_note: p.production_note ?? null,
  }));
  return inPage('PUT', `/api/projects/${scope.project}/items/${scope.item}/branches/${scope.branch}/image-post`, { pages })(page);
}

try {
  // ── 0. 登录 + 进入项目 ────────────────────────────────────────────────
  await page.goto('/auth/login');
  await page.fill('input[type="email"]', 'i06b@example.test');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/projects**', { timeout: 30000 });
  check('login → projects', page.url().includes('/projects'), page.url());

  await page.goto('/projects');
  await page.getByRole('button', { name: '进入' }).first().waitFor({ timeout: 30000 });
  await page.getByRole('button', { name: '进入' }).first().click();
  await page.waitForURL('**/columns', { timeout: 30000 });
  const project = Number(page.url().match(/projects\/(\d+)/)[1]);
  check('project selected (ProjectContext)', Number.isInteger(project), page.url());

  const itemsResponse = await inPage('GET', `/api/projects/${project}/items`)(page);
  const scope = { project, item: null, branch: null };
  for (const entry of itemsResponse.json.data) {
    const eligible = entry.content_branches.find((b) => b.channel === 'wechat_official' && b.format === 'image_post');
    if (eligible) {
      scope.item = entry.id;
      scope.branch = eligible.id;
      break;
    }
  }
  check('fixture item/branch resolved', Boolean(scope.item && scope.branch), JSON.stringify(scope));

  // ── A. Reusable Asset 正式页面（未挂 AdminLayout，仅验无横向滚动） ─────
  await page.goto(`/projects/${project}/reusable-assets`);
  await page.waitForSelector('[data-v2-asset-grid]', { timeout: 30000 });
  const raHeading = await page.locator('h1').first().innerText();
  check('A1. Reusable Asset page heading', raHeading.includes('人物 / 场景复用索引'), raHeading);

  const tabText = await page.locator('[data-v2-asset-tabs]').innerText();
  check('A2. tabs show counts 人物(2)/场景(2)', /人物（2）/.test(tabText) && /场景（2）/.test(tabText), tabText.replace(/\n/g, ' ').slice(0, 40));

  const defaultCards = await page.locator('[data-v2-reusable-asset]').count();
  check('A3. default 人物 tab renders 2 cards', defaultCards === 2, `cards=${defaultCards}`);

  const cardText = async (name) => {
    const loc = page.locator('[data-v2-reusable-asset]', { hasText: name });
    return (await loc.count()) > 0 ? (await loc.first().innerText()).replace(/\s+/g, ' ').trim() : '';
  };
  const momText = await cardText('妈妈');
  const dadText = await cardText('爸爸');
  check('A4. 妈妈 = 已有', momText.includes('妈妈') && momText.includes('已有'), momText);
  check('A5. 爸爸 = 缺失', dadText.includes('爸爸') && dadText.includes('缺失'), dadText);

  await page.locator('[data-v2-asset-tabs]').getByRole('button', { name: /场景/ }).click();
  await page.waitForTimeout(400);
  const sceneCards = await page.locator('[data-v2-reusable-asset]').count();
  check('A6. 场景 tab renders 2 cards', sceneCards === 2, `cards=${sceneCards}`);
  const livingText = await cardText('客厅');
  const kitchenText = await cardText('厨房');
  check('A7. 客厅 = 已有', livingText.includes('客厅') && livingText.includes('已有'), livingText);
  check('A8. 厨房 = 缺失', kitchenText.includes('厨房') && kitchenText.includes('缺失'), kitchenText);

  await page.locator('[data-v2-asset-filters]').getByRole('button', { name: /缺失/ }).click();
  await page.waitForTimeout(300);
  check('A9. filter 缺失 → 1 card (厨房)', (await page.locator('[data-v2-reusable-asset]').count()) === 1);
  await page.locator('[data-v2-asset-filters]').getByRole('button', { name: /已有/ }).click();
  await page.waitForTimeout(300);
  check('A10. filter 已有 → 1 card (客厅)', (await page.locator('[data-v2-reusable-asset]').count()) === 1);
  await page.locator('[data-v2-asset-filters]').getByRole('button', { name: /全部/ }).click();
  await page.waitForTimeout(200);

  // 全页无横向滚动（Reusable Asset 页，未挂 AdminLayout）
  await assertNoHScroll(page, 353);
  await assertNoHScroll(page, 390);
  await page.setViewportSize({ width: 1280, height: 900 });

  // ── B. ImageBatch 正式页面（基于 AdminLayout → 含 Mobile Drawer） ─────
  const batchUrl = `/projects/${scope.project}/content/${scope.item}/branches/${scope.branch}/image-batch`;
  await page.goto(batchUrl);
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  check('B1. ImageBatch page entered', page.url().endsWith(batchUrl), page.url());

  const unitCards = await page.locator('[data-image-batch-unit]').count();
  check('B2. GET workspace renders 3 units', unitCards === 3, `units=${unitCards}`);
  const bodyText = await page.locator('body').innerText();
  check('B3. server content title rendered', bodyText.includes('柠檬熊'), bodyText.replace(/\n/g, ' ').slice(0, 40));

  const assetsText = await page.locator('[data-v2-image-batch-assets]').innerText();
  check('B4. I06B 默认空语义：assets 空状态', assetsText.includes('暂无资产检查数据'), assetsText.replace(/\n/g, ' ').slice(0, 40));

  // 全页无横向滚动 + Mobile Drawer（ImageBatch 基于 AdminLayout）
  await assertDrawer(page, 353);
  await assertDrawer(page, 390);
  await page.setViewportSize({ width: 1280, height: 900 });

  // ── F. ImageBatch 全流程 ─────────────────────────────────────────────
  await page.fill('textarea[aria-label="整批生图说明"]', '柠檬黄暖调 · 儿童日常插画');
  check('F1. batch instruction edited → dirty', (await page.locator('text=有未保存修改').count()) > 0);

  await page.fill('textarea[aria-label="整批备注"]', '封面留白多一点');
  check('F2. batch note edited', (await page.locator('textarea[aria-label="整批备注"]').inputValue()) === '封面留白多一点');

  const firstCard = page.locator('[data-image-batch-unit="P1"]');
  await firstCard.getByRole('button', { name: '轻量修正' }).click();
  await firstCard.locator('textarea').fill('P1 加一只纸箱道具');
  await firstCard.getByRole('button', { name: '保存' }).click();
  check('F3. unit special requirement edited', (await firstCard.innerText()).includes('P1 加一只纸箱道具'));

  const historyToggle = () => page.getByRole('button', { name: /查看历史批次|收起历史批次/ });
  const historyCount = async () => Number((await historyToggle().innerText()).match(/（(\d+)）/)?.[1] ?? -1);
  const startCount = await historyCount();
  check('F4. history baseline read', startCount >= 0, `start=${startCount}`);

  // Draft Save：整批生成先 PUT 保存再 run
  await page.getByRole('button', { name: '整批生成' }).click();
  await page.waitForFunction(() => document.body.innerText.includes('已保存'), null, { timeout: 30000 });
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 1; }, startCount, { timeout: 30000 });
  check('F5. dirty → Draft Save(PUT) → full run → history +1', (await historyCount()) === startCount + 1, `${startCount} → ${await historyCount()}`);

  await historyToggle().click();
  const historyText = await page.locator('[data-v2-image-batch-history]').innerText();
  check('F6. history shows queued + full coverage', /排队|queued/.test(historyText) && historyText.includes('P1、P2、P3'), historyText.replace(/\n/g, ' ').slice(0, 120));
  await page.getByRole('button', { name: /收起历史批次/ }).click();

  // 注意：保存草稿按钮在「非 dirty」时按设计保持 disabled（已保存后 dirty=false）。
  // 因此只校验真正的执行动作按钮（整批生成 / 先做试样 / 选择部分页面生成 / 复制整批生图任务）
  // 是否可用，排除保存草稿。
  await page.waitForFunction(() => {
    const buttons = [...document.querySelectorAll('[data-v2-image-batch-actions] button')];
    const actionButtons = buttons.filter((b) => b.textContent.trim() !== '保存草稿');
    return actionButtons.length > 0 && actionButtons.every((b) => !b.disabled);
  }, null, { timeout: 30000 });

  await page.locator('[data-image-batch-unit="P2"] input[type="checkbox"]').check();
  await page.getByRole('button', { name: '先做试样' }).click();
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 2; }, startCount, { timeout: 30000 });
  check('F7. trial with selected pages', (await historyCount()) === startCount + 2);

  await page.locator('[data-image-batch-unit="P3"] input[type="checkbox"]').check();
  await page.getByRole('button', { name: '选择部分页面生成' }).click();
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 3; }, startCount, { timeout: 30000 });
  check('F8. partial with selected pages', (await historyCount()) === startCount + 3);

  await page.getByRole('button', { name: '复制整批生图任务' }).click();
  await page.waitForSelector('text=已复制整批任务', { timeout: 30000 });
  const clipboard = await page.evaluate(() => navigator.clipboard.readText());
  check('F9. copy-task uses server task', clipboard.includes('柠檬黄暖调') && clipboard.includes('P1 加一只纸箱道具'), clipboard.slice(0, 60).replace(/\n/g, ' / '));
  check('F10. clipboard read back', clipboard.trim().length > 10, `${clipboard.length} chars`);

  await page.reload();
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });

  const mutateResult = await mutateSource(scope, 1, '来源页真实改动后的文案 v1');
  check('F11a. source page edited via real API', mutateResult.status === 200, `${mutateResult.status}`);
  await page.reload();
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  const warnings = await page.locator('[data-image-batch-unit]').locator('text=来源内容后来有修改').count();
  check('F11. real source change detected', warnings === 1, `unitWarnings=${warnings}`);

  await page.locator('[data-image-batch-unit="P1"]').getByRole('button', { name: '继续沿用' }).click();
  await page.waitForFunction(() => {
    const card = document.querySelector('[data-image-batch-unit="P1"]');
    return card !== null && !card.innerText.includes('来源内容后来有修改');
  }, null, { timeout: 30000 });
  const keptText = await page.locator('[data-image-batch-unit="P1"]').innerText();
  check('F12. keep → clears warning', !keptText.includes('来源内容后来有修改'));
  check('F13. keep keeps production status', keptText.includes('待制作'), keptText.replace(/\n/g, ' ').slice(0, 40));

  await mutateSource(scope, 1, '来源页真实改动后的文案 v2');
  await page.reload();
  await page.waitForSelector('text=来源内容后来有修改', { timeout: 30000 });
  await page.locator('[data-image-batch-unit="P1"]').getByRole('button', { name: '重新生成' }).click();
  await page.waitForFunction(() => {
    const card = document.querySelector('[data-image-batch-unit="P1"]');
    return card !== null && !card.innerText.includes('来源内容后来有修改');
  }, null, { timeout: 30000 });
  const regeneratedText = await page.locator('[data-image-batch-unit="P1"]').innerText();
  check('F14. regenerate → needs_revision', regeneratedText.includes('需修改'), regeneratedText.replace(/\n/g, ' ').slice(0, 40));

  // ── E. console 0 意外错误 ────────────────────────────────────────────
  const realErrors = consoleErrors.filter((t) => !/favicon/i.test(t));
  check('E1. console 0 error', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));
  check('E2. no unexpected 5xx', badRequests.length === 0, badRequests.slice(0, 2).join(' | '));
} catch (err) {
  check('FATAL', false, String(err && err.stack ? err.stack : err).slice(0, 400));
} finally {
  await browser.close();
}

console.log(`\n${results.filter((r) => r.ok).length}/${results.length} browser checks passed`);
process.exit(failures === 0 ? 0 : 1);
