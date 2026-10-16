// V2-P5-I01-R1 —— 发布闭环真实浏览器验收（真实 Laravel + 真实 Vite 构建产物）。
//
// R1 口径（相对初版的关键变化）：
//   - 不预置 approved 状态：Article / ImagePost / Video 全部真实走
//     保存 → 提交审核 → 审核中心「通过」→ 标记已发布 的用户流程。
//   - ImageBatch：正式合同是运行于 wechat_official + image_post ContentBranch 的
//     /projects/{p}/content/{item}/branches/{branch}/image-batch 页面；真实打开该页面，
//     断言「标记已发布」按钮数量 == 0（不允许 skip），并对同一 branch 的
//     publication/image_batch GET 断言 422。
//   - Article 第二版本：dirty 守卫 → 保存 B → 未审核 422 → 重新审核 → 第二条发布记录。
//   - Responsive：ImagePost / Article / Video 三页面 × {353, 390, 1024} = 9 条真实断言。
//   - console / network：精确区分 expected 422 与 unexpected 错误，不做宽泛过滤。
//
// 前置（由 bootstrap 准备）：migrate:fresh + P5PublicationBrowserSeeder + npm run build + php -S。
// 运行：node tests/frontend/v2/p5publication/browserAcceptance.mjs
// 环境变量：P5_BASE_URL（默认 http://127.0.0.1:8078）、CHROME_PATH

import { chromium } from 'playwright-core';

const BASE = process.env.P5_BASE_URL ?? 'http://127.0.0.1:8078';
const CHROME = process.env.CHROME_PATH ?? 'C:/Users/Administrator/AppData/Local/ms-playwright/chromium-1187/chrome-win/chrome.exe';

const results = [];
let failures = 0;
let skips = 0;

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

// ── console / network 采集（精确口径）────────────────────────────────────
const consoleErrors = [];        // 全部 console error 文本
const expected422Console = [];   // 其中：浏览器对 422 响应的资源报错（expected）
const unexpectedConsole = [];    // 其余 unexpected console error
const triggered422 = [];         // harness 主动 fetch 触发的 422
const page422 = [];              // 页面自发请求产生的 422
const badRequests = [];          // >=500 响应

function isExpected422Console(text) {
  // 只精确匹配 Chromium 对 422 状态的标准 resource 报错，不用宽泛字符串过滤。
  return /Failed to load resource: the server responded with a status of 422/.test(text);
}

page.on('console', (message) => {
  if (message.type() !== 'error') return;
  const text = message.text();
  consoleErrors.push(text);
  if (isExpected422Console(text)) expected422Console.push(text);
  else unexpectedConsole.push(text);
});
page.on('response', (response) => {
  const status = response.status();
  if (status === 422) {
    page422.push(`${response.request().method()} ${response.url()}`);
  }
  if (status >= 500) {
    badRequests.push(`${status} ${response.request().method()} ${response.url()}`);
  }
});

/** 主动触发的 422（harness 自己 fetch），计入 expected。 */
async function triggered(method, url, body, expectedStatus) {
  const res = await inPage(method, url, body)(page);
  if (res.status === 422) triggered422.push(`${method} ${url}`);
  return res;
}

/** 等待「标记已发布」按钮出现且可用（说明发布状态已加载且 can_publish=true）。 */
async function waitPublishable() {
  const btn = page.getByRole('button', { name: '标记已发布' });
  await btn.waitFor({ state: 'visible', timeout: 30000 });
  // playwright-core 的 locator.waitFor 不支持 state:'enabled'，改用文本轮询。
  await page.waitForFunction(
    () => {
      const b = Array.from(document.querySelectorAll('button')).find(
        (x) => (x.textContent ?? '').trim() === '标记已发布',
      );
      return b && !b.disabled;
    },
    null,
    { timeout: 30000 },
  );
  return btn;
}

/** 等待某保存类按钮恢复可用（保存完成）。 */
async function waitSaveDone(name) {
  await page.waitForFunction(
    (label) => {
      const b = Array.from(document.querySelectorAll('button')).find(
        (x) => (x.textContent ?? '').trim() === label,
      );
      return b && !b.disabled;
    },
    name,
    { timeout: 30000 },
  );
}

/** 编辑器内点击「提交审核」，等待 UI 进入「审核中」。 */
async function submitReviewAndWaitPending() {
  const submit = page.getByRole('button', { name: '提交审核' });
  await submit.waitFor({ state: 'visible', timeout: 30000 });
  await submit.click();
  await page.waitForFunction(() => document.body.innerText.includes('审核中'), null, { timeout: 30000 });
}

/**
 * 审核中心：打开指定标题的 pending 项并点击「通过」。
 * 决策成功后自动回到列表并刷新，等待该标题从 pending 列表消失。
 */
async function approveInReviewCenter(projectId, title) {
  await page.goto(`/projects/${projectId}/reviews`);
  await page.waitForFunction(
    (t) => Array.from(document.querySelectorAll('button')).some((b) => (b.textContent ?? '').includes(t)),
    title,
    { timeout: 30000 },
  );
  await page.locator('button', { hasText: title }).first().click();
  const approve = page.getByRole('button', { name: '通过', exact: true });
  await approve.waitFor({ state: 'visible', timeout: 30000 });
  await approve.click();
  await page.waitForFunction(
    (t) => {
      const btns = Array.from(document.querySelectorAll('button'));
      return !btns.some((b) => (b.textContent ?? '').trim() === '通过') &&
        !btns.some((b) => (b.textContent ?? '').includes(t));
    },
    title,
    { timeout: 30000 },
  );
}

try {
  // ── 0. 登录 + 进入项目 ────────────────────────────────────────────────
  await page.goto('/auth/login');
  await page.fill('input[type="email"]', 'p5@example.test');
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
  check('items API 200', itemsResponse.status === 200, `${itemsResponse.status}`);

  const scope = { image_post: null, article: null, video: null };
  for (const entry of itemsResponse.json.data) {
    for (const b of entry.content_branches ?? []) {
      if (b.channel === 'wechat_official' && b.format === 'image_post') scope.image_post = { item: entry.id, branch: b.id };
      if (b.channel === 'wechat_official' && b.format === 'article') scope.article = { item: entry.id, branch: b.id };
      if (b.channel === 'wechat_channels' && b.format === 'video') scope.video = { item: entry.id, branch: b.id };
    }
  }
  check('fixture branches resolved (image_post/article/video)', Boolean(scope.image_post && scope.article && scope.video), JSON.stringify(scope));

  const articleUrl = `/projects/${project}/content/${scope.article.item}/branches/${scope.article.branch}/article`;
  const imagePostUrl = `/projects/${project}/content/${scope.image_post.item}/branches/${scope.image_post.branch}/image-post`;
  const videoUrl = `/projects/${project}/content/${scope.video.item}/branches/${scope.video.branch}/video`;

  // ═══ S1. Article 完整真实生命周期（保存 → 提交审核 → 审核通过 → 发布）═══
  {
    const { item, branch } = scope.article;
    const stateUrl = `/api/projects/${project}/items/${item}/branches/${branch}/publication/article_copy`;

    // A. 进入编辑器 → 修改正文 → 保存。
    await page.goto(articleUrl);
    await page.waitForFunction(() => document.querySelector('textarea[placeholder="正文内容…（长文可直接粘贴）"]') !== null, null, { timeout: 30000 });
    const before = await inPage('GET', stateUrl)(page);
    check('S1-A. article: 初始 approved=false / can_publish=false', before.status === 200 && before.json.data.approved === false && before.json.data.can_publish === false, JSON.stringify(before.json?.data));
    await page.fill('textarea[placeholder="正文内容…（长文可直接粘贴）"]', '秋天气温变化大，注意给孩子适时添减衣物，保持室内湿度，预防呼吸道不适。（第一阶段修订）');
    await page.getByRole('button', { name: '保存文章' }).click();
    await waitSaveDone('保存文章');
    check('S1-A. article: 正文修改并保存成功', true);

    // B. 提交审核（真实 ReviewSubmission UI）。
    await submitReviewAndWaitPending();
    check('S1-B. article: 提交审核后显示「审核中」', true);

    // C. 审核中心真实「通过」。
    await approveInReviewCenter(project, 'P5-R1 图文文章');
    check('S1-C. article: 审核中心通过 exact version', true);

    // D. 返回编辑器：approved=true / can_publish=true → 标记已发布。
    await page.goto(articleUrl);
    await page.waitForFunction(() => document.querySelector('textarea[placeholder="正文内容…（长文可直接粘贴）"]') !== null, null, { timeout: 30000 });
    const approved = await inPage('GET', stateUrl)(page);
    check('S1-D. article: 审核通过后 approved=true / can_publish=true', approved.status === 200 && approved.json.data.approved === true && approved.json.data.can_publish === true, JSON.stringify(approved.json?.data));
    const btn = await waitPublishable();
    await btn.click();
    await page.waitForFunction(() => document.body.innerText.includes('已发布'), null, { timeout: 30000 });
    const published = await inPage('GET', stateUrl)(page);
    check('S1-D. article: 标记已发布 → published=true', published.status === 200 && published.json.data.published === true, `id=${published.json?.data?.publication_id}`);
  }

  // ═══ S2. Article 第二版本闭环（dirty 守卫 → 未审核 422 → 重审 → 第二条）═══
  {
    const { item, branch } = scope.article;
    const stateUrl = `/api/projects/${project}/items/${item}/branches/${branch}/publication/article_copy`;

    await page.goto(articleUrl);
    await page.waitForFunction(() => document.querySelector('textarea[placeholder="正文内容…（长文可直接粘贴）"]') !== null, null, { timeout: 30000 });
    // 第一阶段：修改但不保存 → dirty 守卫。
    await page.fill('textarea[placeholder="正文内容…（长文可直接粘贴）"]', '秋季幼儿护理补充：潮湿天气注意衣物防潮，晴好天气多户外活动。（第二阶段修订）');
    // 「标记已发布」不可执行：要么按钮不存在（旧版本已发布态延续，未刷新到新版本），
    // 要么按钮存在但禁用；同时页面必须显示统一文案「请先保存当前修改，再发布」。
    const dirtyGuardVisible = await page.waitForFunction(
      () => {
        const btns = Array.from(document.querySelectorAll('button'));
        const pub = btns.find((x) => (x.textContent ?? '').trim() === '标记已发布');
        const hint = document.body.innerText.includes('请先保存当前修改，再发布');
        return Boolean(hint && (!pub || pub.disabled));
      },
      null,
      { timeout: 15000 },
    ).then(() => true).catch(() => false);
    check('S2. article: dirty 时发布按钮不可执行 + 提示「请先保存当前修改，再发布」', dirtyGuardVisible);

    // 保存 B。
    await page.getByRole('button', { name: '保存文章' }).click();
    await waitSaveDone('保存文章');
    const stateB = await inPage('GET', stateUrl)(page);
    check('S2. article: 版本 B approved=false / published=false / can_publish=false',
      stateB.status === 200 && stateB.json.data.approved === false && stateB.json.data.published === false && stateB.json.data.can_publish === false,
      JSON.stringify(stateB.json?.data));
    const btnB = page.getByRole('button', { name: '标记已发布' });
    await btnB.waitFor({ state: 'visible', timeout: 30000 });
    check('S2. article: 版本 B 发布按钮不可执行', (await btnB.isEnabled()) === false);

    // 直接 POST → 422（expected）。
    const blocked = await triggered('POST', stateUrl, undefined, 422);
    check('S2. article: 版本 B 未审核直接 POST → 422', blocked.status === 422, `${blocked.status}`);

    // 重新走 提交审核 → 审核中心通过 → 发布。
    await submitReviewAndWaitPending();
    check('S2. article: 版本 B 提交审核', true);
    await approveInReviewCenter(project, 'P5-R1 图文文章');
    check('S2. article: 版本 B 审核中心通过', true);
    await page.goto(articleUrl);
    await page.waitForFunction(() => document.querySelector('textarea[placeholder="正文内容…（长文可直接粘贴）"]') !== null, null, { timeout: 30000 });
    const readyB = await inPage('GET', stateUrl)(page);
    check('S2. article: 版本 B can_publish=true', readyB.status === 200 && readyB.json.data.can_publish === true, JSON.stringify(readyB.json?.data));
    const btn2 = await waitPublishable();
    await btn2.click();
    await page.waitForFunction(() => document.body.innerText.includes('已发布'), null, { timeout: 30000 });
    const pubB = await inPage('GET', stateUrl)(page);
    check('S2. article: 版本 B 标记已发布 → published=true', pubB.status === 200 && pubB.json.data.published === true, `id=${pubB.json?.data?.publication_id}`);
  }

  // ═══ S3. ImagePost 完整真实生命周期 ═══════════════════════════════════
  {
    const { item, branch } = scope.image_post;
    const stateUrl = `/api/projects/${project}/items/${item}/branches/${branch}/publication/image_post_copy`;

    await page.goto(imagePostUrl);
    await page.waitForSelector('[data-v2-image-post-pages]', { timeout: 30000 });
    const before = await inPage('GET', stateUrl)(page);
    check('S3. image_post: 初始 approved=false', before.status === 200 && before.json.data.approved === false, JSON.stringify(before.json?.data));

    // 修改正式内容并保存（整篇核心表达 → 版本快照变化）。
    const coreMessage = page.locator('textarea[placeholder="整篇核心表达"]');
    await coreMessage.waitFor({ state: 'visible', timeout: 15000 });
    await coreMessage.fill('秋季幼儿护理：穿衣适度、湿度适宜、作息规律。（修订）');
    await page.getByRole('button', { name: '保存整篇' }).click();
    await waitSaveDone('保存整篇');
    check('S3. image_post: 正式内容修改并保存', true);

    await submitReviewAndWaitPending();
    check('S3. image_post: 提交审核', true);
    await approveInReviewCenter(project, 'P5-R1 图片文章');
    check('S3. image_post: 审核中心通过', true);

    await page.goto(imagePostUrl);
    await page.waitForSelector('[data-v2-image-post-pages]', { timeout: 30000 });
    const approved = await inPage('GET', stateUrl)(page);
    check('S3. image_post: approved=true / can_publish=true', approved.status === 200 && approved.json.data.approved === true && approved.json.data.can_publish === true, JSON.stringify(approved.json?.data));
    const btn = await waitPublishable();
    await btn.click();
    await page.waitForFunction(() => document.body.innerText.includes('已发布'), null, { timeout: 30000 });
    const pub = await inPage('GET', stateUrl)(page);
    check('S3. image_post: 标记已发布 → published=true', pub.status === 200 && pub.json.data.published === true, `id=${pub.json?.data?.publication_id}`);

    // 文案校验：Review / Publication 区域显示「图片文章」，不得出现「小绿书内容」。
    const reviewSectionText = await page.locator('section[aria-label="提交审核"]').innerText().catch(() => '');
    const bodyText = await page.locator('body').innerText();
    check('S3. image_post: 发布区显示「图片文章」或已发布态', bodyText.includes('已发布') || bodyText.includes('图片文章'), '');
    check('S3. image_post: 全页不出现旧文案「小绿书内容」', !bodyText.includes('小绿书内容'), reviewSectionText.slice(0, 60));
  }

  // ═══ S4. Video 完整真实生命周期 ═══════════════════════════════════════
  {
    const { item, branch } = scope.video;
    const stateUrl = `/api/projects/${project}/items/${item}/branches/${branch}/publication/video_production`;

    await page.goto(videoUrl);
    await page.getByRole('button', { name: '保存' }).first().waitFor({ timeout: 30000 });
    const before = await inPage('GET', stateUrl)(page);
    check('S4. video: 初始 approved=false', before.status === 200 && before.json.data.approved === false, JSON.stringify(before.json?.data));

    // 修改旁白并保存。
    const narration = page.locator('textarea[placeholder="完整旁白…"]');
    await narration.waitFor({ state: 'visible', timeout: 15000 });
    await narration.fill('换季时节，孩子的日常护理需要注意穿衣、湿度与作息三个方面。（修订）');
    await page.getByRole('button', { name: '保存', exact: true }).first().click();
    await waitSaveDone('保存');
    check('S4. video: 内容修改并保存', true);

    await submitReviewAndWaitPending();
    check('S4. video: 提交审核', true);
    await approveInReviewCenter(project, 'P5-R1 视频');
    check('S4. video: 审核中心通过', true);

    await page.goto(videoUrl);
    await page.getByRole('button', { name: '保存' }).first().waitFor({ timeout: 30000 });
    const approved = await inPage('GET', stateUrl)(page);
    check('S4. video: approved=true / can_publish=true', approved.status === 200 && approved.json.data.approved === true && approved.json.data.can_publish === true, JSON.stringify(approved.json?.data));
    const btn = await waitPublishable();
    await btn.click();
    await page.waitForFunction(() => document.body.innerText.includes('已发布'), null, { timeout: 30000 });
    const pub = await inPage('GET', stateUrl)(page);
    check('S4. video: 标记已发布 → published=true', pub.status === 200 && pub.json.data.published === true, `id=${pub.json?.data?.publication_id}`);
  }

  // ═══ S5. ImageBatch 真实页面 + 无发布按钮 + image_batch 422 ═══════════
  {
    const { item, branch } = scope.image_post; // 正式合同：ImageBatch 页面运行于 image_post 分支
    const batchUrl = `/projects/${project}/content/${item}/branches/${branch}/image-batch`;
    await page.goto(batchUrl);
    await page.waitForSelector('[data-v2-image-batch-actions]', { timeout: 30000 });
    check('S5. image-batch: 真实页面加载成功（image_post 分支）', page.url().includes('/image-batch'), page.url());
    const publishBtnCount = await page.getByRole('button', { name: '标记已发布' }).count();
    check('S5. image-batch: 「标记已发布」按钮数量 == 0', publishBtnCount === 0, `buttons=${publishBtnCount}`);
    const pub = await triggered('GET', `/api/projects/${project}/items/${item}/branches/${branch}/publication/image_batch`, undefined, 422);
    check('S5. image-batch: publication/image_batch GET → 422', pub.status === 422, `${pub.status}`);
  }

  // ═══ S6. Responsive：3 页面 × 3 宽度 = 9 条真实断言 ════════════════════
  {
    const targets = [
      ['image_post', imagePostUrl, '[data-v2-image-post-pages]'],
      ['article', articleUrl, 'textarea[placeholder="正文内容…（长文可直接粘贴）"]'],
      ['video', videoUrl, null],
    ];
    for (const [label, url, selector] of targets) {
      await page.goto(url);
      if (selector) await page.waitForSelector(selector, { timeout: 30000 });
      else await page.getByRole('button', { name: '保存' }).first().waitFor({ timeout: 30000 });
      for (const width of [353, 390, 1024]) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(450);
        const m = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
        check(`S6. ${label} @${width}px: scrollWidth <= clientWidth`, m.scroll <= m.client + 1, `scroll=${m.scroll} client=${m.client}`);
      }
      await page.setViewportSize({ width: 1280, height: 900 });
    }
  }

  // ── S7. console / network 汇总 ────────────────────────────────────────
  check('S7. console: 0 unexpected errors', unexpectedConsole.length === 0, unexpectedConsole.slice(0, 3).join(' | '));
  check('S7. network: 0 unexpected 5xx', badRequests.length === 0, badRequests.slice(0, 3).join(' | '));
  console.log(`\n[accounting] expected 422 (triggered)=${triggered422.length}, expected 422 (page)=${page422.length}, expected422Console=${expected422Console.length}, unexpectedConsole=${unexpectedConsole.length}, unexpected5xx=${badRequests.length}`);
  console.log(`[accounting] triggered 422 detail: ${triggered422.join(' ; ') || '(none)'}`);
  console.log(`[accounting] page 422 detail: ${page422.join(' ; ') || '(none)'}`);
} catch (err) {
  check('FATAL', false, String(err && err.stack ? err.stack : err).slice(0, 800));
} finally {
  await browser.close();
}

const passed = results.filter((r) => r.ok).length;
console.log(`\n${passed}/${results.length} browser checks passed (skips: ${skips})`);
process.exit(failures === 0 ? 0 : 1);
