import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
const bundle = await build({
  entryPoints: ['resources/js/composables/v2/publication/usePublication.ts'],
  bundle: true,
  write: false,
  format: 'esm',
  platform: 'browser',
  plugins: [{
    name: 'transport',
    setup(b) {
      b.onResolve({ filter: /publicationApi$|api\/errors$/ }, a => ({ path: a.path, namespace: 'mock' }));
      b.onLoad({ filter: /.*/, namespace: 'mock' }, a => ({
        contents: a.path.endsWith('publicationApi') ? 'export const publicationApi={};' : 'export const firstErrorMessage=e=>e.message;',
        loader: 'js',
      }));
    },
  }],
});
const { usePublication } = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));

const readyState = {
  target_type: 'article_copy',
  current_version_label: '内容版本 abc123',
  approved: true,
  review_request_id: 1,
  published: false,
  publication_id: null,
  published_at: null,
  can_publish: true,
  reason: null,
};

test('loads state, publishes when can_publish, and blocks dirty or duplicate clicks', async () => {
  let dirty = false, finish;
  const calls = [];
  const state = { ...readyState, published: false, publication_id: null, published_at: null };
  const client = {
    async load(...args) { calls.push(['get', ...args]); return { ...state }; },
    async publish(...args) {
      calls.push(['post', ...args]);
      await new Promise(r => (finish = r));
      state.published = true;
      state.publication_id = 5;
      state.published_at = '2026-10-16T10:00:00Z';
      return { created: true, publication: { id: 5 } };
    },
  };
  const s = usePublication(() => ({ projectId: 1, itemId: 2, branchId: 3, target: 'article_copy' }), () => dirty, client);
  await s.load();
  assert.equal(s.canPublish.value, true);
  assert.equal(s.published.value, false);
  dirty = true;
  assert.equal(await s.publish(), false);
  assert.equal(calls.length, 1);
  dirty = false;
  const first = s.publish();
  assert.equal(s.publishing.value, true);
  assert.equal(await s.publish(), false);
  finish();
  await first;
  assert.deepEqual(calls.map(c => c[0]), ['get', 'post', 'get']);
  assert.equal(calls[1].length, 5);
  assert.equal(s.published.value, true);
});

test('error is visible and stale route response never replaces current state', async () => {
  let branchId = 3, finish;
  const client = {
    load(p, i, b) {
      return b === 3 ? new Promise(r => (finish = r)) : Promise.resolve({ ...readyState });
    },
    async publish() { throw Error('unavailable'); },
  };
  const s = usePublication(() => ({ projectId: 1, itemId: 2, branchId, target: 'article_copy' }), () => false, client);
  const old = s.load();
  branchId = 4;
  await s.load();
  finish({ ...readyState });
  await old;
  assert.equal(s.canPublish.value, true);
  assert.equal(await s.publish(), false);
  assert.equal(s.error.value, 'unavailable');
  assert.equal(s.publishing.value, false);
});

test('readiness or save busy blocks publish; GET failure after POST never fabricates published; can_publish false blocks', async () => {
  let blocked = true, fail = false, canPublish = true;
  const calls = [];
  const client = {
    async load() {
      calls.push('get');
      if (fail) throw Error('reload failed');
      return { ...readyState, can_publish: canPublish, published: false };
    },
    async publish() {
      calls.push('post');
      fail = true;
      return { created: true, publication: { id: 9 } };
    },
  };
  const s = usePublication(() => ({ projectId: 1, itemId: 2, branchId: 3, target: 'article_copy' }), () => blocked, client);
  await s.load();
  assert.equal(await s.publish(), false);
  assert.deepEqual(calls, ['get']);
  blocked = false;
  canPublish = false;
  await s.load();
  assert.equal(s.canPublish.value, false);
  assert.equal(await s.publish(), false);
  assert.deepEqual(calls, ['get', 'get']);
  canPublish = true;
  await s.load();
  assert.equal(await s.publish(), false);
  assert.equal(s.published.value, false);
  assert.equal(s.error.value, 'reload failed');
  assert.deepEqual(calls, ['get', 'get', 'get', 'post', 'get']);
});

test('already published blocks publish and surfaces timestamp', async () => {
  const pubState = { ...readyState, published: true, publication_id: 7, published_at: '2026-10-16T10:00:00Z' };
  const client = {
    async load() { return pubState; },
    async publish() { throw Error('should not publish'); },
  };
  const s = usePublication(() => ({ projectId: 1, itemId: 2, branchId: 3, target: 'article_copy' }), () => false, client);
  await s.load();
  assert.equal(s.published.value, true);
  assert.equal(s.publishedAt.value, '2026-10-16T10:00:00Z');
  assert.equal(await s.publish(), false);
});
