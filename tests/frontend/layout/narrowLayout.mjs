// V2-P3-H01 —— Foundation 353px 窄屏全局横向溢出修复：布局回归 + 真实浏览器验收。
//
// 前置：
//   1. 准备数据：php artisan migrate:fresh --force
//                php artisan tinker --execute="require '<seed>';"
//   2. 构建前端：npm run build
//   3. 启动服务：php -S 127.0.0.1:8003 -t public <h01-server-router.php>
//   4. 依赖：playwright-core + 本机 chromium（CHROME_PATH）
//
// 运行：
//   node tests/frontend/layout/narrowLayout.mjs
//
// 说明：全流程真实（真实登录、真实 Inertia 页面、真实 Foundation 路由）。
//   只修全局 AdminLayout / Shell，不碰任何业务模块。

import { chromium } from 'playwright-core';

const BASE = process.env.H01_BASE_URL ?? 'http://127.0.0.1:8003';
const CHROME = process.env.CHROME_PATH ?? 'C:/Users/Administrator/AppData/Local/ms-playwright/chromium-1187/chrome-win/chrome.exe';

const results = [];
let failures = 0;

function check(step, ok, detail = '') {
  results.push({ step, ok, detail });
  if (!ok) failures++;
  const tag = ok ? 'PASS' : 'FAIL';
  console.log(`${tag}  ${step}${detail ? ' — ' + detail : ''}`);
}

const browser = await chromium.launch({ executablePath: CHROME, headless: true });
const context = await browser.newContext({
  baseURL: BASE,
  viewport: { width: 1280, height: 900 },
});
const page = await context.newPage();

const consoleErrors = [];
page.on('console', (message) => {
  if (message.type() !== 'error') return;
  const text = message.text();
  if (/favicon/i.test(text)) return;
  consoleErrors.push(text);
});
page.on('pageerror', (error) => consoleErrors.push(`pageerror: ${error.message}`));

const setViewport = (w) => page.setViewportSize({ width: w, height: 900 });
const scrollInfo = () =>
  page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }));
const noHScroll = async (w, label) => {
  await setViewport(w);
  await page.waitForTimeout(250);
  const r = await scrollInfo();
  check(`${label}: document 无横向滚动 (${w}px)`, r.scrollWidth <= r.clientWidth + 1, JSON.stringify(r));
};

async function asideState() {
  return page.evaluate(() => {
    const a = document.querySelector('aside');
    if (!a) return null;
    const r = a.getBoundingClientRect();
    return { left: Math.round(r.left), width: Math.round(r.width), position: getComputedStyle(a).position };
  });
}

// ── 登录 ──────────────────────────────────────────────────────────────────
async function login() {
  await page.goto('/auth/login');
  // 等 SPA  hydration 完成再交互，否则点提交会触发原生表单 GET 回退到登录页。
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(800);
  await page.fill('#auth-email', 'h01@example.test');
  await page.fill('#auth-password', 'password');
  await page.click('button[type="submit"]');
  await page.waitForSelector('aside', { timeout: 10000 });
  await page.waitForURL('**/projects**', { timeout: 10000 });
}

// 选中项目（设置会话当前项目，便于访问 workspace / content 路由）。
async function selectProject(id) {
  await page.selectOption('header select', String(id));
  await page.waitForTimeout(400);
}

// ── 主流程 ────────────────────────────────────────────────────────────────
try {
  await login();
  check('0. 登录成功并进入工作台', true);

  // ── §9 布局回归（桌面） ─────────────────────────────────────────────────
  await setViewport(1280);
  await page.waitForTimeout(200);
  const d1280 = await asideState();
  check('1. 桌面 Sidebar 正常存在（static / ~240px / 可见）',
    d1280 && d1280.position === 'static' && d1280.width >= 220 && d1280.width <= 260 && d1280.left >= -1,
    JSON.stringify(d1280));

  await setViewport(1440);
  await page.waitForTimeout(200);
  const d1440 = await asideState();
  check('10. 1440px 正常（Sidebar 可见、无文档横向滚动）',
    d1440 && d1440.position === 'static' && d1440.width >= 220 && d1440.width <= 260,
    JSON.stringify(d1440));
  {
    const r = await scrollInfo();
    check('10b. 1440px 无横向滚动', r.scrollWidth <= r.clientWidth + 1, JSON.stringify(r));
  }

  await setViewport(768);
  await page.waitForTimeout(200);
  const d768 = await asideState();
  check('9. 768px 正常（布局恢复桌面、Sidebar 可见）',
    d768 && d768.position === 'static' && d768.width >= 220 && d768.width <= 260,
    JSON.stringify(d768));
  {
    const r = await scrollInfo();
    check('9b. 768px 无横向滚动', r.scrollWidth <= r.clientWidth + 1, JSON.stringify(r));
  }

  // ── §9 布局回归（移动） ─────────────────────────────────────────────────
  await setViewport(353);
  await page.waitForTimeout(250);
  const m353 = await asideState();
  check('2. mobile Sidebar 不占主布局宽度（off-canvas fixed / left<0）',
    m353 && m353.position === 'fixed' && m353.left <= -100,
    JSON.stringify(m353));
  // 主内容列应占满视口（sidebar 已脱离文档流）。
  const mainW = await page.evaluate(() => {
    const m = document.querySelector('main');
    return m ? Math.round(m.getBoundingClientRect().width) : -1;
  });
  check('2b. mobile 主内容占满视口宽度', mainW > 300 && mainW <= 353 + 1, `mainWidth=${mainW}`);

  await noHScroll(353, '7. 353px');

  // 3. mobile 菜单可打开
  const hamburger = page.locator('button[aria-label="切换导航菜单"]');
  await hamburger.click();
  await page.waitForTimeout(300);
  const openState = await asideState();
  check('3. mobile 菜单可打开（drawer 进入视口 left≈0）',
    openState && openState.left >= -1,
    JSON.stringify(openState));

  // 4. mobile 菜单可关闭（点遮罩关闭）
  await page.locator('div.fixed.inset-0.z-30').dispatchEvent('click');
  await page.waitForTimeout(300);
  const closedByBackdrop = await asideState();
  check('4. mobile 菜单可关闭（点击遮罩）',
    closedByBackdrop && closedByBackdrop.left <= -100,
    JSON.stringify(closedByBackdrop));

  // 4b. 再次点击菜单按钮也能关闭（再次点击关闭）
  await hamburger.click();
  await page.waitForTimeout(300);
  await hamburger.click();
  await page.waitForTimeout(300);
  const closedByToggle = await asideState();
  check('4b. mobile 菜单再次点击关闭',
    closedByToggle && closedByToggle.left <= -100,
    JSON.stringify(closedByToggle));

  // 5. Project Selector 不撑宽
  const selRight = await page.evaluate(() => {
    const s = document.querySelector('header select');
    return s ? Math.round(s.getBoundingClientRect().right) : -1;
  });
  check('5. Project Selector 不撑宽视口（353px）', selRight <= 353 + 1 && selRight > 0, `selectRight=${selRight}`);

  // 6. 用户区域不撑宽
  const userRight = await page.evaluate(() => {
    const groups = document.querySelectorAll('header > div');
    const userGroup = groups[groups.length - 1];
    return userGroup ? Math.round(userGroup.getBoundingClientRect().right) : -1;
  });
  check('6. 用户区域不撑宽视口（353px）', userRight <= 353 + 1 && userRight > 0, `userGroupRight=${userRight}`);

  // 8. 390px 无横向滚动（先确认抽屉关闭，避免 drawer 影响测量）
  await hamburger.click();
  await page.waitForTimeout(250);
  await page.locator('div.fixed.inset-0.z-30').dispatchEvent('click');
  await page.waitForTimeout(250);
  await noHScroll(390, '8. 390px');

  // ── §10 浏览器验收：真实业务页面 ────────────────────────────────────────
  await setViewport(1280);
  await page.waitForTimeout(150);
  await selectProject(1);

  // Content Library（内容库）
  await page.goto('/projects/1/content');
  await page.waitForSelector('aside', { timeout: 10000 });
  await noHScroll(353, '10a. Content Library 353px');
  await noHScroll(390, '10a. Content Library 390px');
  // 移动菜单在该页可开/关
  await setViewport(353);
  await page.waitForTimeout(200);
  await hamburger.click();
  await page.waitForTimeout(250);
  const clOpen = await asideState();
  check('10b. Content Library 移动菜单可打开', clOpen && clOpen.left >= -1, JSON.stringify(clOpen));
  await page.locator('div.fixed.inset-0.z-30').dispatchEvent('click');
  await page.waitForTimeout(250);
  const clClosed = await asideState();
  check('10b. Content Library 移动菜单可关闭', clClosed && clClosed.left <= -100, JSON.stringify(clClosed));

  // Content detail（文章 / 内容详情页，含视频生产区）—— 代表「Article 或任一正式内容页」
  await setViewport(1280);
  await page.goto('/projects/1/content/1');
  await page.waitForSelector('aside', { timeout: 10000 });
  await noHScroll(353, '10c. Content Detail 353px');
  await noHScroll(390, '10c. Content Detail 390px');

  // 桌面 Sidebar 仍正常（在真实业务页上）
  await setViewport(1280);
  await page.waitForTimeout(200);
  const dt = await asideState();
  check('10d. 真实业务页桌面 Sidebar 仍正常', dt && dt.position === 'static' && dt.width >= 220 && dt.width <= 260, JSON.stringify(dt));

  // console 0 unexpected error
  check('10e. 全程 console 0 unexpected error', consoleErrors.length === 0, consoleErrors.slice(0, 3).join(' | '));
} catch (err) {
  check('FATAL', false, String(err && err.stack ? err.stack.split('\n')[0] : err));
} finally {
  await browser.close();
}

console.log(`\n==== H01 布局验收：${results.length - failures}/${results.length} 通过，${failures} 失败 ====`);
process.exit(failures === 0 ? 0 : 1);
