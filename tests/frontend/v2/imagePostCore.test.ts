import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createImagePostClient, previewHasChanges } from '../../../resources/js/composables/v2/imagePostCore.ts';
test('branch-aware real API envelopes and raw input', async () => {
 const calls: unknown[] = [];
 const workspace = { item: { id: 2, core_message: '表达' }, pages: [], branch: { id: 3 } };
 const preview = { item: { changed: true, fields: [] }, pages: { added: [], modified: [], unchanged: [] }, warnings: [] };
 const transport = {
 async get(url: string) { calls.push(['GET', url]); return { data: workspace }; },
 async put(url: string, data: unknown) { calls.push(['PUT', url, data]); return { data: { data: { status: 'saved' }, warnings: [] } }; },
 async post(url: string, data: unknown) { calls.push(['POST', url, data]); return { data: url.endsWith('/preview') ? preview : { data: { status: 'applied' } } }; },
 };
 const api = createImagePostClient(transport);
 assert.deepEqual(await api.loadWorkspace(1,2,3), workspace);
 assert.deepEqual(await api.preview(1,2,3,'P4'), preview);
 await api.apply(1,2,3,'P4');
 await api.save(1,2,3,{ core_message: '表达', pages: [] });
 const b='/api/projects/1/items/2/branches/3/image-post';
 assert.deepEqual(calls,[['GET',b],['POST',b+'/preview',{raw:'P4'}],['POST',b+'/apply',{raw:'P4'}],['PUT',b,{core_message:'表达',pages:[]}]]);
});
test('item-only changes enable confirmation; warnings and unchanged do not',()=>{
 const p={item:{changed:false,fields:[]},pages:{added:[],modified:[],unchanged:[]},warnings:[]};
 assert.equal(previewHasChanges(p),false);
 assert.equal(previewHasChanges({...p,item:{changed:true,fields:[]}}),true);
});
