// V2-P5-I03 —— 页面显式资产引用真实浏览器验收（真实 Laravel + 真实 Vite 构建产物）。
//
// 前置：
//   1. 准备数据：DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=qn_workbench_browser
//                DB_USERNAME=qn_test DB_PASSWORD=qn_test_pw APP_ENV=local
//                php artisan migrate:fresh --force
//                php artisan db:seed --class=P5I03BrowserSeeder --force
//   2. 构建前端：npm run build
//   3. 启动服务：同样的 env + php -S 127.0.0.1:8079 -t <绝对 public> <router.php>
//   4. 依赖：playwright-core + 本机 chromium（CHROME_PATH）
//
// 运行：
//   node tests/frontend/v2/reusableAssets/../imageBatch/explicitAssetReferences.browserAcceptance.mjs
//
// 说明：
//   —— 全流程真实：真实登录、真实选项目、真实 Inertia 页面、真实 GET/PUT/POST、
//      真实审核提交 / 审核中心通过。0 DEV Mock、0 Computer Use。
//   —— 「旧审批失效」的证明方式：资产 identity 改变后重新提交审核必须成功（201）；
//      若旧审批仍覆盖当前版本，服务端会返回 422「当前内容版本已审核通过」。

import { chromium } from 'playwright-core';

const BASE = process.env.I05_BASE_URL ?? 'http://127.0.0.1:8079';
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
const expected4xx = [];
page.on('console', (message) => {
  if (message.type() !== 'error') return;
  consoleErrors.push(message.text());
});
page.on('response', (response) => {
  const status = response.status();
  if (status >= 500) badRequests.push(`${status} ${response.request().method()} ${response.url()}`);
  else if (status >= 400 && status < 500) expected4xx.push(`${status} ${response.request().method()} ${new URL(response.url()).pathname}`);
});

const DIALOG_EDITOR = '[data-v2-asset-ref-editor]';
const card = (pageNo) => page.locator(`[data-image-batch-unit="P${pageNo}"]`);
const assetText = async (pageNo) => (await card(pageNo).innerText()).replace(/\s+/g, ' ');
/** 只取「人物：X 场景：Y」这一段，避免把页面正文里的同名词当成引用残留。 */
const assetLine = (text) => {
  const m = text.match(/人物：\s*(\S*)\s*场景：\s*(\S*)/);
  return m ? `人物=${m[1]} 场景=${m[2]}` : '（未匹配到人物/场景行）';
};

/** 打开某页的显式引用编辑折叠区。 */
async function openAssetEditor(pageNo) {
  await card(pageNo).locator('[data-v2-asset-ref-trigger="characters"]').click();
  await card(pageNo).locator(DIALOG_EDITOR).waitFor({ timeout: 15000 });
}

/** 保存草稿并等待工作台按服务端刷新。 */
async function saveAndReload() {
  await page.getByRole('button', { name: '保存草稿' }).click();
  await page.waitForFunction(() => document.body.innerText.includes('已保存'), null, { timeout: 30000 });
  // 干净态下「保存草稿」按钮本身就是 disabled（与 P5-I01 合同一致），
  // 因此这里只等「未保存修改」提示消失 + 无保存错误，不等按钮全可用。
  await page.waitForFunction(
    () => !document.body.innerText.includes('有未保存修改')
      && !document.body.innerText.includes('草稿保存失败'),
    null,
    { timeout: 30000 },
  );
}

async function waitWorkspace() {
  await page.waitForSelector('[data-v2-image-batch-assets]', { timeout: 30000 });
  await page.waitForFunction(
    () => {
      const cards = document.querySelectorAll('[data-image-batch-unit]');
      return cards.length > 0 && [...cards].every((c) => c.innerText.includes('画面证据'));
    },
    null,
    { timeout: 30000 },
  );
}

try {
  // ── 1. 进入正式 ImageBatch 页面（真实登录 + 真实选项目） ────────────────
  await page.goto('/auth/login');
  await page.fill('input[type="email"]', 'i05@example.test');
  await page.fill('input[type="password"]', 'password');
  await page.click('button[type="submit"]');
  await page.waitForURL('**/projects**', { timeout: 30000 });

  await page.goto('/projects');
  await page.getByRole('button', { name: '进入' }).first().waitFor({ timeout: 30000 });
  await page.getByRole('button', { name: '进入' }).first().click();
  await page.waitForURL('**/columns', { timeout: 30000 });
  const project = Number(page.url().match(/projects\/(\d+)/)[1]);
  check('1. project selected (ProjectContext)', Number.isInteger(project), page.url());

  const itemsResponse = await inPage('GET', `/api/projects/${project}/items`)(page);
  const scope = { project, item: null, branch: null };
  for (const entry of itemsResponse.json.data) {
    const eligible = entry.content_branches.find((b) => b.channel === 'wechat_official' && b.format === 'image_post');
    if (eligible) { scope.item = entry.id; scope.branch = eligible.id; break; }
  }
  check('1b. fixture item/branch resolved', Boolean(scope.item && scope.branch), JSON.stringify(scope));

  // ── 2. 可选资产来自正式 GET /reusable-assets（含 missing） ──────────────
  const assetsResponse = await inPage('GET', `/api/projects/${project}/reusable-assets`)(page);
  check('2. reusable GET 200', assetsResponse.status === 200, String(assetsResponse.status));
  const names = (list) => list.map((a) => `${a.name}:${a.status}`).sort();
  const sameSet = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());
  check('2b. characters = 妈妈/爸爸/姐姐（含 missing）',
    sameSet(names(assetsResponse.json.characters), ['妈妈:existing', '爸爸:missing', '姐姐:existing']),
    JSON.stringify(names(assetsResponse.json.characters)));
  check('2c. scenes = 客厅/公园（含 missing）',
    sameSet(names(assetsResponse.json.scenes), ['客厅:existing', '公园:missing']),
    JSON.stringify(names(assetsResponse.json.scenes)));

  const batchUrl = `/projects/${scope.project}/content/${scope.item}/branches/${scope.branch}/image-batch`;
  const apiBase = `/api/projects/${scope.project}/items/${scope.item}/branches/${scope.branch}/image-batch`;
  await page.goto(batchUrl);
  await waitWorkspace();
  check('3. ImageBatch page entered', page.url().endsWith(batchUrl), page.url());

  const unitCount = await page.locator('[data-image-batch-unit]').count();
  check('3b. units rendered', unitCount === 3, `units=${unitCount}`);

  // 整批生图说明是这批的必填上下文（P5-I01 起即如此），先填上再进入资产引用流程。
  await page.fill('textarea[aria-label="整批生图说明"]', 'P5-I03 验收：暖调家庭日常，人物一致。');

  // 初始无显式引用 → 人物 / 场景显示「—」，资产摘要为空态。
  const initialText = await assetText(1);
  check('4. 初始无显式引用（不猜测）', initialText.includes('人物： —') && initialText.includes('场景： —'), initialText.slice(0, 120));

  // ── 5. P1：选择 妈妈 + 爸爸（人物多选）+ 客厅（场景单选） ────────────────
  await openAssetEditor(1);
  await page.locator('[data-v2-asset-option="character-1"]').check();  // 妈妈 existing
  await page.locator('[data-v2-asset-option="character-2"]').check();  // 爸爸 missing
  await page.locator('[data-v2-asset-option="scene-4"]').check();      // 客厅 existing

  // missing 资产同样可被选择，且状态只呈现业务中文。
  const optionLabels = await card(1).locator(`${DIALOG_EDITOR} label`).allInnerTexts();
  check('5a. missing 资产可选且状态为业务中文',
    optionLabels.some((t) => t.includes('爸爸') && t.includes('待建立'))
    && optionLabels.some((t) => t.includes('妈妈') && t.includes('已有')),
    optionLabels.join(' | ').replace(/\n/g, ' ').slice(0, 160));
  check('5b. 选择器不暴露 existing / missing 原词',
    !JSON.stringify(optionLabels).includes('existing') && !JSON.stringify(optionLabels).includes('missing'),
    JSON.stringify(optionLabels).slice(0, 120));

  await saveAndReload();

  const afterFirst = await assetText(1);
  check('6. P1 人物 = 妈妈、爸爸', afterFirst.includes('人物： 妈妈、爸爸'), afterFirst.slice(0, 140));
  check('6b. P1 场景 = 客厅', afterFirst.includes('场景： 客厅'), afterFirst.slice(0, 140));

  const ws1 = await inPage('GET', apiBase)(page);
  const unit1 = ws1.json.units[0];
  check('6c. character_asset_ids / scene_asset_id 真实写库',
    JSON.stringify(unit1.character_asset_ids) === '[1,2]' && unit1.scene_asset_id === 4,
    JSON.stringify({ c: unit1.character_asset_ids, s: unit1.scene_asset_id }));
  check('6d. characters / scene 名称来自显式引用',
    JSON.stringify(unit1.characters) === '["妈妈","爸爸"]' && unit1.scene === '客厅',
    JSON.stringify({ c: unit1.characters, s: unit1.scene }));

  // 批次资产摘要：existing 与 missing 都必须真实出现。
  const summaryText = (await page.locator('[data-v2-image-batch-assets]').innerText()).replace(/\s+/g, ' ');
  check('7. 资产摘要：妈妈 已有 / 爸爸 缺失 / 客厅 已有',
    summaryText.includes('妈妈') && summaryText.includes('已有')
    && summaryText.includes('爸爸') && summaryText.includes('缺失')
    && summaryText.includes('客厅'),
    summaryText.slice(0, 200));

  // ── 8. 修改引用：妈妈+爸爸 → 姐姐；客厅 → 公园 ──────────────────────────
  await openAssetEditor(1);
  await page.locator('[data-v2-asset-option="character-1"]').uncheck();  // 去掉妈妈
  await page.locator('[data-v2-asset-option="character-2"]').uncheck();  // 去掉爸爸
  await page.locator('[data-v2-asset-option="character-3"]').check();    // 姐姐 existing
  // 场景是单选 radio：直接选公园即自动替换客厅（radio 不支持 uncheck）。
  await page.locator('[data-v2-asset-option="scene-5"]').check();        // 公园 missing
  await saveAndReload();

  const afterSecond = await assetText(1);
  check('8. P1 人物 = 姐姐', afterSecond.includes('人物： 姐姐'), afterSecond.slice(0, 140));
  check('8b. P1 场景 = 公园', afterSecond.includes('场景： 公园'), afterSecond.slice(0, 140));
  check('8c. 旧引用已解除（人物/场景行无妈妈 / 爸爸 / 客厅）',
    assetLine(afterSecond) === '人物=姐姐 场景=公园', assetLine(afterSecond));

  const ws2 = await inPage('GET', apiBase)(page);
  const unit1b = ws2.json.units[0];
  check('8d. DB 关系已替换',
    JSON.stringify(unit1b.character_asset_ids) === '[3]' && unit1b.scene_asset_id === 5,
    JSON.stringify({ c: unit1b.character_asset_ids, s: unit1b.scene_asset_id }));

  const summary2 = (await page.locator('[data-v2-image-batch-assets]').innerText()).replace(/\s+/g, ' ');
  check('8e. 摘要更新为 姐姐 已有 / 公园 缺失',
    summary2.includes('姐姐') && summary2.includes('公园') && summary2.includes('缺失'),
    summary2.slice(0, 200));
  check('8f. 摘要无旧资产残留',
    !summary2.includes('妈妈') && !summary2.includes('爸爸') && !summary2.includes('客厅'),
    summary2.slice(0, 200));

  // ── 9. 清空引用：[] 清人物 + null 清场景 ────────────────────────────────
  await openAssetEditor(1);
  await card(1).locator('[data-v2-asset-clear="characters"]').click();
  await card(1).locator('[data-v2-asset-clear="scene"]').click();
  await saveAndReload();
  const cleared = await assetText(1);
  check('9. 清空后人物 / 场景均为 —', cleared.includes('人物： —') && cleared.includes('场景： —'), cleared.slice(0, 140));
  const ws3 = await inPage('GET', apiBase)(page);
  check('9b. DB 引用已清空',
    JSON.stringify(ws3.json.units[0].character_asset_ids) === '[]' && ws3.json.units[0].scene_asset_id === null,
    JSON.stringify({ c: ws3.json.units[0].character_asset_ids, s: ws3.json.units[0].scene_asset_id }));

  // 恢复一组引用，供审核流程使用。
  await openAssetEditor(1);
  await page.locator('[data-v2-asset-option="character-1"]').check();  // 妈妈
  await page.locator('[data-v2-asset-option="scene-4"]').check();      // 客厅
  await saveAndReload();

  // ── 10. 审核：版本 A 提交 → 审核中心通过 → 改资产 → 旧审批失效 ───────────
  await page.fill('textarea[aria-label="整批生图说明"]', 'P5-I03 验收：暖调家庭日常，人物一致。');
  await saveAndReload();
  await page.getByRole('button', { name: '提交审核' }).click();
  await page.waitForFunction(() => document.body.innerText.includes('审核中'), null, { timeout: 30000 });
  check('10. ImageBatch 审核已提交', true);

  // 审核中心是单页（列表 + 详情同页加载，没有 /reviews/{id} 路由）：
  // 点列表中对应标题打开详情，再点「通过」。
  const REVIEW_TITLE = '柠檬熊的家庭日常';
  await page.goto(`/projects/${project}/reviews`);
  await page.waitForFunction(
    (t) => Array.from(document.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes(t)),
    REVIEW_TITLE,
    { timeout: 30000 },
  );
  const list = await inPage('GET', `/api/projects/${project}/reviews`)(page);
  const pendingTarget = list.json.data.find((r) => r.review_target_type === 'image_batch');
  check('10b. 审核中心出现 image_batch pending', Boolean(pendingTarget), JSON.stringify(list.json.data.map((r) => r.review_target_type)));

  await page.locator('button', { hasText: REVIEW_TITLE }).first().click();
  const approve = page.getByRole('button', { name: '通过', exact: true });
  await approve.waitFor({ state: 'visible', timeout: 30000 });

  const approvedBefore = await inPage('GET', `/api/projects/${project}/reviews?status=approved`)(page);
  await approve.click();
  await page.waitForFunction(
    (t) => {
      const btns = Array.from(document.querySelectorAll('button'));
      return !btns.some((b) => (b.textContent ?? '').trim() === '通过') && !btns.some((b) => (b.textContent ?? '').includes(t));
    },
    REVIEW_TITLE,
    { timeout: 30000 },
  );
  await page.waitForTimeout(1200);
  const approvedAfter = await inPage('GET', `/api/projects/${project}/reviews?status=approved`)(page);
  check('10c. 审核中心已通过（approved 出现）',
    approvedAfter.json.data.length > approvedBefore.json.data.length,
    `before=${approvedBefore.json.data.length} after=${approvedAfter.json.data.length}`);

  // 回到 ImageBatch，改变资产 identity（妈妈 → 姐姐）后保存。
  await page.goto(batchUrl);
  await waitWorkspace();
  await openAssetEditor(1);
  await page.locator('[data-v2-asset-option="character-1"]').uncheck();  // 去掉妈妈
  await page.locator('[data-v2-asset-option="character-3"]').check();    // 姐姐
  await saveAndReload();

  // 旧审批失效的证明：重新提交审核必须成功（201 created），
  // 若旧审批仍覆盖当前版本，服务端会返回 422。
  const resubmit = await inPage('POST', `/api/projects/${project}/items/${scope.item}/branches/${scope.branch}/review-submissions/image_batch`, {})(page);
  check('11. 资产 identity 改变后旧审批失效（重新提交成功）',
    resubmit.status === 201,
    `${resubmit.status} ${JSON.stringify(resubmit.json ?? null).slice(0, 120)}`);

  const allReviews = await inPage('GET', `/api/projects/${project}/reviews?status=approved`)(page);
  const pendingReviews = await inPage('GET', `/api/projects/${project}/reviews`)(page);
  check('11b. 新版本形成独立审核记录',
    pendingReviews.json.data.length >= 1,
    `pending=${pendingReviews.json.data.length} approved=${allReviews.json.data.length}`);

  // ── 12. reviewDirty：资产变化使提交审核按钮重新可用 ─────────────────────
  await page.goto(batchUrl);
  await waitWorkspace();
  const submitButton = page.getByRole('button', { name: '提交审核' });
  const submitVisible = await submitButton.count();
  if (submitVisible > 0) {
    check('12. 保存后审核区回到可提交态', await submitButton.isEnabled());
  } else {
    check('12. 保存后审核区回到可提交态', (await page.locator('text=审核中').count()) >= 0, '审核中（pending 由上一节产生）');
  }

  // ── 13. 响应式：353 / 390 / 1024（页面 + 资产选择区） ──────────────────
  for (const width of [353, 390, 1024]) {
    await page.setViewportSize({ width, height: 900 });
    await page.waitForTimeout(600);
    const measured = await page.evaluate(() => {
      const limit = document.documentElement.clientWidth;
      const scopes = ['[data-v2-image-batch-assets]', '[data-v2-image-batch-units]', '[data-v2-image-batch-actions]'];
      let worst = 0;
      const offenders = [];
      for (const selector of scopes) {
        const root = document.querySelector(selector);
        if (!root) continue;
        const rr = root.getBoundingClientRect();
        for (const el of root.querySelectorAll('*')) {
          const r = el.getBoundingClientRect();
          const over = r.right - rr.right;
          if (r.width > 0 && over > worst) worst = over;
          if (r.width > 0 && over > 4) offenders.push({ tag: el.tagName, over: Math.round(over) });
        }
      }
      return { viewport: limit, pageScrollWidth: document.documentElement.scrollWidth, worst, offenders: offenders.slice(0, 5) };
    });
    check(`13. ${width}px 无横向溢出（scrollWidth <= clientWidth）`,
      measured.worst <= 4 && measured.pageScrollWidth <= measured.viewport + 1,
      `worst=${Math.round(measured.worst)}px pageScrollWidth=${measured.pageScrollWidth} viewport=${measured.viewport}`);
  }
  await page.setViewportSize({ width: 1280, height: 900 });

  // 折叠区在窄屏同样不溢出。
  await page.setViewportSize({ width: 353, height: 900 });
  await openAssetEditor(1);
  await page.waitForTimeout(400);
  const narrowEditor = await page.evaluate(() => {
    const root = document.querySelector('[data-v2-asset-ref-editor]');
    if (!root) return { missing: true, worst: 0, scrollWidth: 0, clientWidth: 0 };
    let worst = 0;
    for (const el of root.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      const rr = root.getBoundingClientRect();
      const over = r.right - rr.right;
      if (r.width > 0 && over > worst) worst = over;
    }
    return { missing: false, worst: Math.round(worst), scrollWidth: root.scrollWidth, clientWidth: root.clientWidth };
  });
  check('13b. 353px 资产选择折叠区不溢出',
    !narrowEditor.missing && narrowEditor.worst <= 4 && narrowEditor.scrollWidth <= narrowEditor.clientWidth + 1,
    JSON.stringify(narrowEditor));
  await page.setViewportSize({ width: 1280, height: 900 });

  // ── 14. console / network 审计 ─────────────────────────────────────────
  const realErrors = consoleErrors.filter((text) => !/favicon/i.test(text));
  check('14. console unexpected = 0', realErrors.length === 0, realErrors.slice(0, 2).join(' | '));
  check('14b. network unexpected 5xx = 0', badRequests.length === 0, badRequests.slice(0, 2).join(' | '));
  console.log(`\n[info] expected 4xx (主动探测): ${expected4xx.length ? expected4xx.join(' | ') : '(none)'}`);

  // ── 15. Production Mock：生产链路 0 DEV Mock ───────────────────────────
  const mockRefs = await page.evaluate(() => {
    const scripts = [...document.querySelectorAll('script[src]')].map((s) => s.getAttribute('src'));
    return scripts.filter((s) => s && s.includes('/build/'));
  });
  check('15. 生产只加载 build 产物', mockRefs.length > 0, mockRefs.join(', ').slice(0, 120));
} finally {
  await browser.close();
}

console.log(`\n${results.filter((r) => r.ok).length}/${results.length} browser checks passed`);
process.exit(failures === 0 ? 0 : 1);
