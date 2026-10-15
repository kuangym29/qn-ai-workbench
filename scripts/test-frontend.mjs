#!/usr/bin/env node
/**
 * 正式前端测试入口 / Canonical frontend suite entry point.
 *
 *   npm run test:frontend
 *
 * 这是仓库唯一的正式 frontend test runner 入口。
 * CI（frontend-gate）与本地必须使用同一入口。
 *
 * Strategy recovers the historical canonical behaviour:
 *
 *   1. *.test.mjs  -> plain `node --test`
 *      These suites are self-contained (no relative source imports).
 *
 *   2. *.test.ts   -> `node --test --experimental-strip-types --import <repo ts-extension loader>`
 *      TypeScript suites import Vue-facing sources that use bundler-style
 *      extensionless relative imports (e.g. './assetIndexApi', './videoCore').
 *      Vite / vue-tsc resolve those, but raw Node ESM requires explicit
 *      extensions. The repository already ships a resolver hook for exactly
 *      this purpose, so we reuse it instead of rewriting product imports.
 *
 * Guarantees:
 *   - 0 new npm dependencies (Node built-in test runner only)
 *   - no test is skipped, renamed, duplicated or deleted
 *   - discovery is automatic: every *.test.mjs / *.test.ts under tests/frontend
 *   - aggregated count + aggregated exit code (fails if any group fails)
 */

import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const TEST_ROOT = 'tests/frontend';

/**
 * 已有 repo loader hook（bundler 式无扩展名解析）。
 * 二者为同一份实现，按存在性选取第一个，避免新增依赖或重复实现。
 */
const LOADER_CANDIDATES = [
  'tests/frontend/v2/video/register-loader.mjs',
  'tests/frontend/v2/reusableAssets/register-loader.mjs',
];

function discover(dir) {
  /** Recursively collect every *.test.mjs / *.test.ts file, sorted for determinism. */
  let out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out = out.concat(discover(full));
    } else if (entry.isFile() && /\.test\.(mjs|ts)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out.sort();
}

function toPosix(p) {
  return p.split(path.sep).join('/');
}

/** Extract node --test TAP summary counters from a run's stdout. */
function summarise(output) {
  const counters = { tests: 0, suites: 0, pass: 0, fail: 0, cancelled: 0, skipped: 0, todo: 0 };
  for (const line of output.split('\n')) {
    const m = /^#\s+(tests|suites|pass|fail|cancelled|skipped|todo)\s+(\d+)/.exec(line.trim());
    if (m) counters[m[1]] += Number(m[2]);
  }
  return counters;
}

function main() {
  const all = discover(TEST_ROOT);
  const mjs = all.filter((f) => f.endsWith('.test.mjs'));
  const ts = all.filter((f) => f.endsWith('.test.ts'));

  if (all.length === 0) {
    console.error(`[test:frontend] No frontend tests found under ${TEST_ROOT}/`);
    process.exit(1);
  }

  const loader = LOADER_CANDIDATES.find((c) => {
    try {
      return readdirSync(path.dirname(c)).includes(path.basename(c));
    } catch {
      return false;
    }
  });

  if (ts.length > 0 && !loader) {
    console.error(
      '[test:frontend] TypeScript suites found but no ts-extension loader hook exists.\n' +
        `Looked for: ${LOADER_CANDIDATES.join(', ')}`,
    );
    process.exit(1);
  }

  /** Groups mirror the historical stable commands, now behind one entry point. */
  const groups = [
    { name: 'mjs', label: '*.test.mjs (node --test)', files: mjs, flags: [] },
    {
      name: 'ts',
      label: '*.test.ts (node --test --experimental-strip-types + repo ts-extension loader)',
      files: ts,
      flags: ['--experimental-strip-types', '--import', `./${toPosix(loader)}`],
    },
  ];

  console.log('============================================================');
  console.log(' V2 Canonical Frontend Suite (npm run test:frontend)');
  console.log('============================================================');
  console.log(` node       : ${process.version}`);
  console.log(` platform   : ${process.platform}`);
  console.log(` test root  : ${TEST_ROOT}/`);
  console.log(` discovered : ${all.length} test files (${mjs.length} .mjs / ${ts.length} .ts)`);
  if (loader) console.log(` ts loader  : ${loader} (existing repo hook, reused)`);
  console.log('============================================================\n');

  const totals = { tests: 0, suites: 0, pass: 0, fail: 0, cancelled: 0, skipped: 0, todo: 0 };
  let failedGroups = 0;

  for (const group of groups) {
    console.log(`--- group: ${group.label} (${group.files.length} files) ---`);

    if (group.files.length === 0) {
      console.log('    (none)\n');
      continue;
    }

    const args = [...group.flags, '--test', ...group.files.map(toPosix)];
    const run = spawnSync(process.execPath, args, {
      stdio: ['ignore', 'pipe', 'pipe'],
      maxBuffer: 64 * 1024 * 1024,
    });

    const stdout = run.stdout ? run.stdout.toString() : '';
    const stderr = run.stderr ? run.stderr.toString() : '';
    const combined = `${stdout}\n${stderr}`;

    const counts = summarise(combined);
    for (const key of Object.keys(totals)) totals[key] += counts[key];

    console.log(
      `    tests=${counts.tests} pass=${counts.pass} fail=${counts.fail} ` +
        `skipped=${counts.skipped} todo=${counts.todo} cancelled=${counts.cancelled}`,
    );

    if (run.status !== 0 || counts.fail > 0) {
      failedGroups += 1;
      console.log(`    group result: FAIL (exit=${run.status})`);
      if (stderr.trim()) console.log(stderr.trim());
      if (stdout.trim()) console.log(stdout.trim());
    } else {
      console.log('    group result: PASS');
    }
    console.log('');
  }

  console.log('============================================================');
  console.log(
    ` TOTAL ${totals.tests} | PASS ${totals.pass} | FAIL ${totals.fail} | ` +
      `SKIPPED ${totals.skipped} | TODO ${totals.todo} | CANCELLED ${totals.cancelled}`,
  );
  console.log(` groups failed: ${failedGroups}`);
  console.log('============================================================');

  if (failedGroups > 0 || totals.fail > 0) {
    process.exit(1);
  }
  process.exit(0);
}

main();
