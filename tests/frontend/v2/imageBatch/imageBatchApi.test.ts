import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createImageBatchClient } from '../../../../resources/js/composables/v2/imageBatch/imageBatchApi.ts';
test('five official endpoints use scoped paths and exact payloads',async()=>{
 const calls: unknown[]=[];
 const http={async get(url:string){calls.push(['get',url]);return {data:{units:[]}};},async put(url:string,body:unknown){calls.push(['put',url,body]);return {data:{status:'saved'}};},async post(url:string,body:unknown){calls.push(['post',url,body]);return {data:{task:'SERVER'}};}};
 const api=createImageBatchClient(http);const base='/api/projects/1/items/2/branches/3/image-batch';
 await api.loadWorkspace(1,2,3);const draft={batch_instruction:'d',batch_note:null,units:[{id:'u1',special_requirement:null}]};await api.saveDraft(1,2,3,draft);await api.run(1,2,3,{mode:'full',unit_ids:[]});assert.deepEqual(await api.copyTask(1,2,3),{task:'SERVER'});await api.resolveSourceChange(1,2,3,'u/4',{action:'keep'});
 assert.deepEqual(calls,[['get',base],['put',base,draft],['post',base+'/run',{mode:'full',unit_ids:[]}],['post',base+'/copy-task',{}],['post',base+'/units/u%2F4/source-change',{action:'keep'}]]);
});
