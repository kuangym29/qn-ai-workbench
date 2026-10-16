import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';

async function moduleFor(path, isolateApi = false) {
  const result = await build({ entryPoints: [path], bundle: true, write: false, format: 'esm', platform: 'browser', plugins: [{ name: 'test-boundaries', setup(b) {
    b.onResolve({ filter: /api\/http$|api\/errors$|ui\/toast$/ }, a => ({ path: a.path, namespace: 'test' }));
    if (isolateApi) b.onResolve({ filter: /videoApi$/ }, a => ({ path: a.path, namespace: 'test' }));
    b.onLoad({ filter: /.*/, namespace: 'test' }, a => ({ contents: a.path.endsWith('videoApi') ? 'export const videoApi={};' : a.path.endsWith('http') ? 'export const http={};' : a.path.endsWith('errors') ? 'export const firstErrorMessage=e=>e.message;' : 'export const toast={success(){},error(){}};', loader: 'js' }));
  } }] });
  return import('data:text/javascript;base64,' + Buffer.from(result.outputFiles[0].text).toString('base64'));
}
const { createVideoClient } = await moduleFor('resources/js/composables/v2/video/videoApi.ts');
const { useVideoEditor } = await moduleFor('resources/js/composables/v2/video/useVideoEditor.ts', true);
function workspace() {
  // Deliberately distinct server values prove that GET truth is not recomputed locally.
  return { video: { id: null, project_id: 1, content_item_id: 2, title: '视频', source_label: '来源', batch_instruction: '', narration: '', source_changed: true, source_change_summary: { changed: true, message: '来源内容已修改', source_page_count: 2 }, units: [{ source_page_id: 41, order: 1, source_label: '封面', dynamic_instruction: '', duration_seconds: 5, note: null }, { source_page_id: 42, order: 2, source_label: 'P2', dynamic_instruction: '', duration_seconds: 5, note: null }] }, total_duration: 27, timeline: [{ order: 1, source_page_id:41, source_label: '服务端时间轴', duration_seconds:27, start_seconds: 0, end_seconds: 27 }], narration_note:'完整旁白对应整条视频' };
}
function setup() {
  const calls = [];
  const api = { async loadWorkspace() { calls.push('get'); return workspace(); }, async save(...args) { calls.push(['put', args[3]]); }, async resolveSourceChange(...args) { calls.push(['keep', args[3]]); } };
  return { editor: useVideoEditor(1, 2, 3, api), api, calls };
}
test('production transport consumes backend raw workspace and branch-aware endpoint', async () => {
  const ws = workspace(); const calls = [];
  const client = createVideoClient({ async get(url) { calls.push(url); return { data: ws }; } });
  assert.deepEqual(await client.loadWorkspace(1, 2, 3), ws);
  assert.deepEqual(calls, ['/api/projects/1/items/2/branches/3/video']);
});
test('unit changes use source_page_id without invented row ids', async () => {
  const { editor: e } = setup(); await e.load(); e.updateUnit(42, { dynamic_instruction: '缓慢推近', duration_seconds: 8, note: '停顿' });
  assert.equal(e.video.value.units[0].dynamic_instruction, '');
  assert.equal(e.video.value.units[1].dynamic_instruction, '缓慢推近');
  assert.equal(e.video.value.units[1].duration_seconds, 8);
});
test('GET restores backend duration and timeline; duration edits expose preview only', async () => {
  const { editor: e } = setup(); await e.load();
  assert.equal(e.totalDuration.value, 27); assert.equal(e.timeline.value[0].source_label, '服务端时间轴');
  e.updateUnit(42, { duration_seconds: 8 }); assert.equal(e.durationPreview.value, true); assert.equal(e.totalDuration.value, 13);
  await e.load(); assert.equal(e.durationPreview.value, false); assert.equal(e.totalDuration.value, 27);
});
test('save whitelists five editable categories and reloads backend truth', async () => {
  const { editor: e, calls } = setup(); await e.load(); e.updateBatchInstruction('整批'); e.updateNarration('旁白'); e.updateUnit(42, { dynamic_instruction: '推近', duration_seconds: 8, note: '停顿' });
  assert.equal(await e.save(), true);
  const payload = calls.find(x => Array.isArray(x) && x[0] === 'put')[1];
  assert.deepEqual(Object.keys(payload).sort(), ['batch_instruction', 'narration', 'units']);
  assert.deepEqual(Object.keys(payload.units[1]).sort(), ['duration_seconds', 'dynamic_instruction', 'note', 'source_page_id']);
  assert.equal(payload.units[1].source_page_id, 42); assert.equal(payload.units[1].dynamic_instruction, '推近'); assert.equal(calls.at(-1), 'get');
});
test('keep failure retains structured source warning and makes no optimistic GET', async () => {
  const { editor: e, api, calls } = setup(); await e.load(); api.resolveSourceChange = async () => { throw Error('失败'); };
  assert.equal(await e.keepSourceChanges(), false); assert.equal(e.video.value.source_changed, true); assert.equal(e.video.value.source_change_summary.message, '来源内容已修改'); assert.deepEqual(calls, ['get']);
});
test('keep success clears warning only through fresh GET', async () => {
  const { editor: e, api, calls } = setup(); await e.load(); api.loadWorkspace = async () => { calls.push('get'); const ws = workspace(); ws.video.source_changed = false; ws.video.source_change_summary = null; return ws; };
  assert.equal(await e.keepSourceChanges(), true); assert.equal(e.video.value.source_changed, false); assert.deepEqual(calls, ['get', ['keep', { action: 'keep' }], 'get']);
});
test('failed dirty keep preserves draft and warning without PUT or GET', async () => {
  const { editor: e, api, calls } = setup(); await e.load(); e.updateNarration('未保存旁白');
  api.resolveSourceChange = async () => { calls.push('failed keep'); throw Error('沿用失败'); };
  assert.equal(await e.keepSourceChanges(), false); assert.deepEqual(calls, ['get', 'failed keep']);
  assert.equal(e.video.value.narration, '未保存旁白'); assert.equal(e.video.value.source_changed, true);
});
test('dirty keep refreshes system truth while preserving editable draft without PUT', async () => {
  const { editor: e, api, calls } = setup(); await e.load(); e.updateNarration('保留编辑'); e.updateUnit(42,{duration_seconds:8,note:'草稿'});
  api.loadWorkspace = async () => { calls.push('get'); const ws=workspace(); ws.video.source_changed=false; ws.video.units.push({source_page_id:43,order:3,source_label:'新增',dynamic_instruction:'',duration_seconds:3,note:null}); return ws; };
  assert.equal(await e.keepSourceChanges(), true); assert.deepEqual(calls,['get',['keep',{action:'keep'}],'get']); assert.equal(e.video.value.narration,'保留编辑');
  assert.equal(e.video.value.units[1].note,'草稿'); assert.equal(e.video.value.units[2].source_page_id,43); assert.equal(e.dirty.value,true); assert.equal(e.durationPreview.value,true); assert.equal(e.video.value.source_changed,false);
});
test('pending save blocks edits that refresh would overwrite', async () => {
  const { editor: e, api } = setup(); await e.load(); let finish;
  api.save = () => new Promise(resolve => { finish = resolve; });
  const pending = e.save(); e.updateNarration('冲突编辑'); e.updateUnit(42, { note: '冲突编辑' });
  assert.equal(e.video.value.narration, ''); assert.equal(e.video.value.units[1].note, null);
  finish(); await pending;
});
test('preview timeline matches backend row fields and invents no narration allocation', async () => {
  const { editor: e, api } = setup();
  api.loadWorkspace = async () => { const ws=workspace(); ws.narration_note='完整旁白对应整条视频'; ws.timeline=[{order:1,source_page_id:41,source_label:'封面',duration_seconds:5,start_seconds:0,end_seconds:5}]; return ws; };
  await e.load(); assert.equal(e.narrationNote.value, '完整旁白对应整条视频'); e.updateUnit(42,{duration_seconds:8});
  assert.deepEqual(Object.keys(e.timeline.value[0]).sort(), ['duration_seconds','end_seconds','order','source_label','source_page_id','start_seconds']);
});
test('decimal duration edits preserve backend supported precision', async () => {
  const {editor:e}=setup(); await e.load(); e.updateUnit(42,{duration_seconds:3.5}); assert.equal(e.video.value.units[1].duration_seconds,3.5);
});
