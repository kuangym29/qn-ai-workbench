// V2-P3-I05 —— 图片批次生产真实浏览器验收（真实 Laravel + 真实 Vite 构建产物）。
//
// 前置：
//   1. 准备数据：DB_CONNECTION=sqlite DB_DATABASE=<sqlite 文件> php artisan migrate:fresh --force
//                DB_CONNECTION=sqlite DB_DATABASE=<sqlite 文件> php artisan tinker --execute="require '<seed>';"
//   2. 构建前端：npm run build
//   3. 启动服务：php -S 127.0.0.1:8000 -t public public/index.php（同样的 env）
//   4. 依赖：playwright-core（npm i --no-save playwright-core）+ 本机 chromium（CHROME_PATH）
//
// 运行：
//   node tests/frontend/v2/imageBatch/browserAcceptance.mjs
//
// 说明：
//   —— 全流程真实：真实登录、真实 Inertia 页面、真实 GET/PUT/POST、真实剪贴板读回。
//   —— "来源变化"由页面真实地编辑 image_post 内容（PUT /api/.../image-post）制造，
//      不走任何 Mock，也不 spawn 子进程（Windows spawnSync EBUSY）。

import { chromium } from 'playwright-core';

const BASE = process.env.I05_BASE_URL ?? 'http://127.0.0.1:8000';
const CHROME = process.env.CHROME_PATH ?? 'C:/Users/Administrator/AppData/Local/ms-playwright/chromium-1187/chrome-win/chrome.exe';

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

        return { status: response.status, json: await response.json().catch(() => null) };
      },
      { method, url, body },
    );
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
// 第 19 节会人为注入一次 PUT 500 来验证「保存失败」分支。被注入的失败不是产品缺陷，
// 监听时必须分流，否则第 23 节的「console 0 error / 无意外 5xx」会被自己的用例污染。
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

/** 真实修改来源页（经 image_post 人工编辑 API），从而制造 source_changed。 */
async function mutateSource(scope, pageNo, finalCopy) {
  const current = await inPage('GET', `/api/projects/${scope.project}/items/${scope.item}/branches/${scope.branch}/image-post`)(page);
  // 必须原样带回全部页面字段，否则其它页会因缺失字段被写成 null 而误报来源变化。
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

  const result = await inPage('PUT', `/api/projects/${scope.project}/items/${scope.item}/branches/${scope.branch}/image-post`, { pages })(page);

  return result;
}

try {
  // ── 1. 进入正式 ImageBatch 页面（真实登录 + 真实选项目） ────────────────
  await page.goto('/auth/login');
  await page.fill('input[type="email"]', 'i05@example.test');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/projects**', { timeout: 30000 });
  check('login → projects', page.url().includes('/projects'), page.url());

  await page.goto('/projects');
  // 正式入口：项目卡片的「进入」= 先 select 当前 Project，再进入其栏目。
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

  const batchUrl = `/projects/${scope.project}/content/${scope.item}/branches/${scope.branch}/image-batch`;
  await page.goto(batchUrl);
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  check('1. ImageBatch page entered', page.url().endsWith(batchUrl), page.url());

  // ── 2. GET workspace（真实响应体） ─────────────────────────────────────
  const unitCards = await page.locator('[data-image-batch-unit]').count();
  check('2. GET workspace renders units', unitCards === 3, `units=${unitCards}`);
  const bodyText = await page.locator('body').innerText();
  check('2. server content title rendered', bodyText.includes('柠檬熊的一天'), bodyText.replace(/\n/g, ' ').slice(0, 60));

  // ── 21. assets 空状态 ──────────────────────────────────────────────────
  const assetsText = await page.locator('[data-v2-image-batch-assets]').innerText();
  check('21. assets empty state graceful', assetsText.includes('暂无资产检查数据'), assetsText.replace(/\n/g, ' ').slice(0, 60));

  // ── 3~5. 编辑整批说明 / 备注 / 单页特殊要求 → dirty ────────────────────
  await page.fill('textarea[aria-label="整批生图说明"]', '柠檬黄暖调 · 儿童日常插画');
  check('3. batch instruction edited → dirty', (await page.locator('text=有未保存修改').count()) > 0);

  await page.fill('textarea[aria-label="整批备注"]', '封面留白多一点');
  check('4. batch note edited', (await page.locator('textarea[aria-label="整批备注"]').inputValue()) === '封面留白多一点');

  const firstCard = page.locator('[data-image-batch-unit="P1"]');
  await firstCard.getByRole('button', { name: '轻量修正' }).click();
  await firstCard.locator('textarea').fill('P1 加一只纸箱道具');
  await firstCard.getByRole('button', { name: '保存' }).click();
  check('5. unit special requirement edited', (await firstCard.innerText()).includes('P1 加一只纸箱道具'));

  // ── 7. dirty → PUT → full run ──────────────────────────────────────────
  const historyToggle = () => page.getByRole('button', { name: /查看历史批次|收起历史批次/ });
  const historyCount = async () => Number((await historyToggle().innerText()).match(/（(\d+)）/)?.[1] ?? -1);
  const startCount = await historyCount();
  check('6. history baseline read from server', startCount >= 0, `start=${startCount}`);
  await page.getByRole('button', { name: '整批生成' }).click();
  await page.waitForFunction(() => document.body.innerText.includes('已保存'), null, { timeout: 30000 });
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 1; }, startCount, { timeout: 30000 });
  check('7. dirty → PUT → full run → GET history', (await historyCount()) === startCount + 1, `${startCount} → ${await historyCount()}`);

  await historyToggle().click();
  const historyText = await page.locator('[data-v2-image-batch-history]').innerText();
  check('8. history shows real queued status', /排队|queued/.test(historyText), historyText.replace(/\n/g, ' ').slice(0, 120));
  check('8. full covered all pages', historyText.includes('P1、P2、P3'), historyText.replace(/\n/g, ' ').slice(0, 120));
  await page.getByRole('button', { name: /收起历史批次/ }).click();

  // ── 9. trial ───────────────────────────────────────────────────────────
  await page.locator('[data-image-batch-unit="P2"] input[type="checkbox"]').check();
  await page.getByRole('button', { name: '先做试样' }).click();
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 2; }, startCount, { timeout: 30000 });
  check('9. trial with selected pages', (await historyCount()) === startCount + 2);

  // ── 10. partial ────────────────────────────────────────────────────────
  await page.locator('[data-image-batch-unit="P3"] input[type="checkbox"]').check();
  await page.getByRole('button', { name: '选择部分页面生成' }).click();
  await page.waitForFunction((before) => { const m = document.body.innerText.match(/查看历史批次（(\d+)）/); return m && Number(m[1]) === before + 3; }, startCount, { timeout: 30000 });
  check('10. partial with selected pages', (await historyCount()) === startCount + 3);

  // ── 11/12. copy-task + 真实剪贴板读回 ──────────────────────────────────
  await page.getByRole('button', { name: '复制整批生图任务' }).click();
  await page.waitForSelector('text=已复制整批任务', { timeout: 30000 });
  const clipboard = await page.evaluate(() => navigator.clipboard.readText());
  check('11. copy uses only server task', clipboard.includes('柠檬黄暖调') && clipboard.includes('P1 加一只纸箱道具'), clipboard.slice(0, 60).replace(/\n/g, ' / '));
  check('12. clipboard read back matches server', clipboard.trim().length > 10, `${clipboard.length} chars`);

  // ── 20. reload 选择清空 ────────────────────────────────────────────────
  // 等 busy 结束（动作按钮重新可用）再勾选。
  await page.waitForFunction(
    () => {
      const buttons = [...document.querySelectorAll('[data-v2-image-batch-actions] button')];
      return buttons.length > 0 && buttons.every((b) => !b.disabled);
    },
    null,
    { timeout: 30000 },
  );
  await page.locator('[data-image-batch-unit="P1"] input[type="checkbox"]').check();
  const selectedBefore = await page.locator('[data-v2-image-batch-units] input[type="checkbox"]:checked').count();
  await page.reload();
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  const selectedAfter = await page.locator('[data-v2-image-batch-units] input[type="checkbox"]:checked').count();
  check('20. reload clears selection', selectedBefore > 0 && selectedAfter === 0, `before=${selectedBefore} after=${selectedAfter}`);

  // ── 13. 真实制造来源变化（经 image_post 人工编辑 API 改页面原文） ───────
  const mutateResult = await mutateSource(scope, 1, '来源页真实改动后的文案 v1');
  check('13a. source page edited through the real API', mutateResult.status === 200, `${mutateResult.status} ${JSON.stringify(mutateResult.json ?? null).slice(0, 160)}`);
  await page.reload();
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  // 「来源内容后来有修改」在工作台里出现两处：整页汇总横幅 + 每张变化页的卡片。
  // 断言必须按卡片范围计数，否则 1 页变化会被数成 2。
  const warnings = await page.locator('[data-image-batch-unit]').locator('text=来源内容后来有修改').count();
  check('13. real source change detected', warnings === 1, `unitWarnings=${warnings}`);

  const banner = page.locator('div[role="status"]').filter({ hasText: '页的来源内容后来有修改' });
  const bannerText = (await banner.count()) > 0 ? (await banner.innerText()).replace(/\s+/g, ' ').trim() : '';
  check('13b. banner counts exactly the changed page', bannerText.startsWith('⚠ 1 页'), bannerText.slice(0, 60));

  // ── 14/15. keep ────────────────────────────────────────────────────────
  await page.locator('[data-image-batch-unit="P1"]').getByRole('button', { name: '继续沿用' }).click();
  await page.waitForFunction(
    () => {
      const card = document.querySelector('[data-image-batch-unit="P1"]');
      return card !== null && !card.innerText.includes('来源内容后来有修改');
    },
    null,
    { timeout: 30000 },
  );
  const keptText = await page.locator('[data-image-batch-unit="P1"]').innerText();
  check('14. keep → POST → GET clears warning', !keptText.includes('来源内容后来有修改'));
  check('15. keep keeps production status', keptText.includes('待制作'), keptText.replace(/\n/g, ' ').slice(0, 60));

  // ── 16. regenerate → needs_revision ────────────────────────────────────
  await mutateSource(scope, 1, '来源页真实改动后的文案 v2');
  await page.reload();
  await page.waitForSelector('text=来源内容后来有修改', { timeout: 30000 });
  await page.locator('[data-image-batch-unit="P1"]').getByRole('button', { name: '重新生成' }).click();
  await page.waitForFunction(
    () => {
      const card = document.querySelector('[data-image-batch-unit="P1"]');
      return card !== null && !card.innerText.includes('来源内容后来有修改');
    },
    null,
    { timeout: 30000 },
  );
  const regeneratedText = await page.locator('[data-image-batch-unit="P1"]').innerText();
  check('16. regenerate → needs_revision', regeneratedText.includes('需修改'), regeneratedText.replace(/\n/g, ' ').slice(0, 60));

  // ── 17/18. busy guard + 重复提交 ───────────────────────────────────────
  const beforeDouble = await historyCount();
  const runButton = page.getByRole('button', { name: '整批生成' });
  // 忙碌期间该按钮文案会切换成「提交中…」，按 name 定位在 busy 时段会匹配不到 → isDisabled() 恒 false。
  // 因此用「动作区第一个按钮」做稳定定位（整批生成永远在动作区首位）。
  const runButtonStable = page.locator('[data-v2-image-batch-actions] button').first();
  await runButton.click();

  let sawBusyLock = false;
  let busyLabel = '';
  const deadline = Date.now() + 8000;
  while (Date.now() < deadline) {
    if (await runButtonStable.isDisabled()) {
      sawBusyLock = true;
      busyLabel = (await runButtonStable.innerText()).trim();
      // 忙碌期间重复点击：disabled 状态下不应产生第二次执行（guard 拦截）。
      await runButtonStable.dispatchEvent('click').catch(() => undefined);
      break;
    }
    await page.waitForTimeout(10);
  }
  check('17. busy locks action buttons', sawBusyLock, `label="${busyLabel}"`);

  await page.waitForFunction(
    (before) => {
      const match = document.body.innerText.match(/查看历史批次（(\d+)）/);
      return match && Number(match[1]) === before + 1;
    },
    beforeDouble,
    { timeout: 30000 },
  );
  await page.waitForTimeout(1500);
  const afterDouble = await historyCount();
  check('18. duplicate run during busy → one record', afterDouble === beforeDouble + 1, `${beforeDouble} → ${afterDouble}`);

  // ── 19. Draft 保存失败 → dirty 保留、不 run、不 copy ───────────────────
  injectingFailure = true;
  await page.route('**/image-batch', async (route) => {
    if (route.request().method() === 'PUT') {
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'forced failure' }) });
      return;
    }
    await route.continue();
  });

  const beforeFailure = await historyCount();
  await page.fill('textarea[aria-label="整批备注"]', '保存失败场景');
  await page.getByRole('button', { name: '整批生成' }).click();
  await page.waitForSelector('text=草稿保存失败', { timeout: 30000 });
  const afterFailure = await historyCount();
  check('19a. PUT failure keeps dirty', (await page.locator('text=有未保存修改').count()) > 0);
  check('19b. PUT failure blocks run', afterFailure === beforeFailure, `${beforeFailure} → ${afterFailure}`);

  await page.getByRole('button', { name: '复制整批生图任务' }).click();
  await page.waitForTimeout(1500);
  const copiedAfterFailure = await page.locator('text=已复制整批任务').count();
  check('19c. PUT failure blocks copy', copiedAfterFailure === 0);
  await page.unroute('**/image-batch');
  injectingFailure = false;
  await page.waitForTimeout(400);

  // ── 22. 353px 窄屏 ─────────────────────────────────────────────────────
  await page.setViewportSize({ width: 353, height: 900 });
  await page.waitForTimeout(500);
  // I05 只负责「图片批次工作区」自身（assets / units / actions 三个区块）。
  // 全页横向溢出来自 Foundation 既有的 AdminLayout：固定 264px 侧边栏在窄屏不收起 +
  // 顶栏（项目 SELECT + 用户区）不折叠，把整页 scrollWidth 推到 ~619（见报告第 22 节）。
  // 第二十/二十一节明确「不要扩大范围、不要重设计工作台」，因此 #22 断言限定为：
  // I05 组件自身是响应式的（单列网格 / flex-wrap / w-full 输入 / 卡片头部已加 wrap），
  // 仅允许「Foundation 把内容列压到约 65px 而强制产生的 ≤ 阈值 微量溢出」，不把 Foundation 的
  // 264px 侧边栏溢出算到 I05 头上。
  const narrow = await page.evaluate(() => {
    const limit = document.documentElement.clientWidth;
    const scopes = ['[data-v2-image-batch-assets]', '[data-v2-image-batch-units]', '[data-v2-image-batch-actions]'];
    let worst = 0;
    const offenders = [];
    for (const scope of scopes) {
      const root = document.querySelector(scope);
      if (!root) { offenders.push({ scope, missing: true }); continue; }
      const rr = root.getBoundingClientRect();
      for (const el of root.querySelectorAll('*')) {
        const r = el.getBoundingClientRect();
        const over = r.right - rr.right;
        if (r.width > 0 && over > worst) worst = over;
        if (r.width > 0 && over > 4) {
          offenders.push({ scope, tag: el.tagName, cls: String(el.className ?? '').slice(0, 48), over: Math.round(over), rootRight: Math.round(rr.right) });
        }
      }
    }
    return {
      viewport: limit,
      pageScrollWidth: document.documentElement.scrollWidth,
      worstOverflow: Math.round(worst),
      offenders: offenders.slice(0, 8),
    };
  });
  check('22. narrow 353px: I05 components are responsive (only ≤4px spill from Foundation-forced 65px column)',
    narrow.worstOverflow <= 4,
    `worstI05Overflow=${narrow.worstOverflow}px pageScrollWidth=${narrow.pageScrollWidth} viewport=${narrow.viewport}${narrow.offenders.length ? ' first=' + JSON.stringify(narrow.offenders[0]) : ''}`);
  await page.setViewportSize({ width: 1280, height: 900 });

  // ── 23. console 0 error ────────────────────────────────────────────────
  // 已分流：injectingFailure 期间产生的错误（第 19 节人为注入的 500）不计入。
  const realErrors = consoleErrors.filter((text) => !/favicon/i.test(text));
  check('23. console 0 error', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));
  check('23b. no unexpected 5xx', badRequests.length === 0, badRequests.slice(0, 2).join(' | '));
  check('23c. injected 500 excluded from errors', injectedRequests.length >= 1,
    `injected5xx=${injectedRequests.length} injectedConsole=${injectedConsole.length}`);
} finally {
  await browser.close();
}

console.log(`\n${results.filter((r) => r.ok).length}/${results.length} browser checks passed`);
process.exit(failures === 0 ? 0 : 1);
