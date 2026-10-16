import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { build } from 'esbuild';
const result = await build({ entryPoints:['resources/js/composables/v2/imageBatch/useImageBatch.ts'],bundle:true,write:false,format:'esm',platform:'browser',plugins:[{name:'isolate',setup(b){
 b.onResolve({filter:/api\/http$|api\/errors$|ui\/toast$/},a=>({path:a.path,namespace:'isolated'}));
 b.onLoad({filter:/.*/,namespace:'isolated'},a=>({contents:a.path.endsWith('http')?'export const http={};':a.path.endsWith('errors')?'export const firstErrorMessage=e=>e.message;':'export const toast={success(){},error(){}};',loader:'js'}));
}}] });
const {useImageBatch}=await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
const clone=x=>JSON.parse(JSON.stringify(x));
function fixture(){
 let data={content_title:'t',source_label:'s',batch_instruction:'initial',batch_note:null,assets:[],history:[],units:[{id:'u1',page_id:1,page_no:1,page_type:'content',final_copy:'system',visual_evidence:'system',characters:[],scene:null,special_requirement:null,status:'generated',source_changed:true}]};
 const calls=[];
 const client={async loadWorkspace(){calls.push('get');return clone(data);},async saveDraft(p,i,b,input){calls.push(['put',clone(input)]);data={...data,...input,units:data.units.map(u=>({...u,special_requirement:input.units.find(x=>x.id===u.id).special_requirement}))};},async run(p,i,b,input){calls.push(['run',input]);return {status:'pending'};},async copyTask(){calls.push('copy');return {task:'SERVER TASK'};},async resolveSourceChange(p,i,b,id,input){calls.push(['source',id,input]);data.units[0].source_changed=false;data.units[0].status=input.action==='keep'?'generated':'needs_revision';}};
 const e=useImageBatch(1,2,3,client,async text=>calls.push(['clipboard',text]));return {e,client,calls};
}
test('selection is local; successful GET and reload clear selection and dirty',async()=>{const {e}=fixture();await e.load();e.toggleUnit('u1');assert.equal(e.selectedUnitIds.value.has('u1'),true);assert.equal('selected' in e.units.value[0],false);assert.equal(e.selection.value.selected,1);await e.load();assert.equal(e.selectedUnitIds.value.size,0);assert.equal(e.dirty.value,false);e.selectAll();assert.equal(e.allSelected.value,true);e.clearSelection();assert.equal(e.selection.value.selected,0);});
test('review dirty ignores selection, detects nested edits, resets after save GET, survives GET failure',async()=>{
 const {e,client,calls}=fixture();await e.load();e.selectAll();assert.equal(e.reviewDirty.value,false);
 e.updateBatchInstruction('new');assert.equal(e.reviewDirty.value,true);e.updateBatchInstruction('initial');assert.equal(e.reviewDirty.value,false);
 e.updateUnit('u1',{special_requirement:'new'});assert.equal(e.reviewDirty.value,true);await e.saveDraft();assert.equal(e.reviewDirty.value,false);assert.equal(calls.at(-1),'get');
 e.updateBatchNote('modified');client.loadWorkspace=async()=>{throw Error('offline');};assert.equal(await e.saveDraft(),false);assert.equal(e.reviewDirty.value,true);
});
test('explicit draft save preserves selected units that still exist',async()=>{
 const {e}=fixture();await e.load();e.selectAll();e.updateBatchNote('saved');await e.saveDraft();assert.equal(e.selectedUnitIds.value.has('u1'),true);
});
for(const [name,edit] of [['instruction',e=>e.updateBatchInstruction('new')],['note',e=>e.updateBatchNote('note')],['requirement',e=>e.updateUnit('u1',{special_requirement:'special'})]])test(`${name} marks dirty; successful PUT clears it`,async()=>{const {e,calls}=fixture();await e.load();edit(e);assert.equal(e.dirty.value,true);assert.equal(await e.saveDraft(),true);assert.equal(e.dirty.value,false);const input=calls.find(x=>Array.isArray(x)&&x[0]==='put')[1];assert.deepEqual(Object.keys(input).sort(),['batch_instruction','batch_note','units']);assert.deepEqual(Object.keys(input.units[0]).sort(),['id','special_requirement']);});
for(const mode of ['full','trial','partial'])test(`dirty ${mode}: PUT then run then GET; exact targets`,async()=>{const {e,calls}=fixture();await e.load();e.updateBatchInstruction('new');if(mode!=='full')e.toggleUnit('u1');assert.equal(await e.run(mode),true);assert.deepEqual(calls.map(x=>Array.isArray(x)?x[0]:x),['get','put','run','get']);assert.deepEqual(calls[2][1],{mode,unit_ids:mode==='full'?[]:['u1']});});
for(const mode of ['trial','partial'])test(`${mode} rejects empty selection before saving`,async()=>{const {e,calls}=fixture();await e.load();e.updateBatchNote('n');assert.equal(await e.run(mode),false);assert.deepEqual(calls,['get']);});
for(const action of ['run','copy','save','source'])test(`failed Draft remains dirty and stops ${action}`,async()=>{const {e,client,calls}=fixture();await e.load();e.updateBatchNote('n');client.saveDraft=async()=>{calls.push('failed put');throw Error('offline');};if(action==='run')await e.run('full');else if(action==='copy')await e.copyTask();else if(action==='source')await e.keepCurrent('u1');else await e.saveDraft();assert.equal(e.dirty.value,true);assert.deepEqual(calls,['get','failed put']);assert.match(e.runError.value,/保存失败/);});
test('dirty copy saves first and copies only the returned task',async()=>{const {e,calls}=fixture();await e.load();e.updateBatchNote('n');await e.copyTask();assert.deepEqual(calls.map(x=>Array.isArray(x)?x[0]:x),['get','put','copy','clipboard']);assert.equal(calls.at(-1)[1],'SERVER TASK');assert.equal(e.copied.value,true);});
for(const action of ['keep','regenerate'])test(`${action} calls source API then GET; no optimistic state`,async()=>{const {e,client,calls}=fixture();await e.load();const original=client.resolveSourceChange;let finish;client.resolveSourceChange=(...args)=>new Promise(resolve=>{finish=async()=>{await original(...args);resolve();};});const pending=action==='keep'?e.keepCurrent('u1'):e.regenerate('u1');assert.equal(e.units.value[0].source_changed,true);assert.equal(e.units.value[0].status,'generated');await Promise.resolve();await finish();await pending;assert.deepEqual(calls,['get',['source','u1',{action}],'get']);assert.equal(e.units.value[0].source_changed,false);});
for(const operation of ['save','run','copy','source'])test(`${operation} busy blocks edits, reload and duplicate actions`,async()=>{const {e,client,calls}=fixture();await e.load();e.updateBatchNote('n');let finish;const original=client.saveDraft;client.saveDraft=(...args)=>new Promise(resolve=>{finish=async()=>{await original(...args);resolve();};});const pending=operation==='save'?e.saveDraft():operation==='run'?e.run('full'):operation==='copy'?e.copyTask():e.keepCurrent('u1');assert.equal(e.busy.value,true);e.updateBatchInstruction('race');e.updateUnit('u1',{special_requirement:'race'});e.toggleUnit('u1');assert.equal(e.workspace.value.batch_instruction,'initial');assert.equal(e.units.value[0].special_requirement,null);assert.equal(e.selection.value.selected,0);assert.equal(await e.load(),false);await e.run('full');await e.copyTask();await e.regenerate('u1');assert.deepEqual(calls,['get']);await finish();await pending;assert.equal(e.busy.value,false);assert.equal(calls.filter(x=>Array.isArray(x)&&x[0]==='put').length,1);});
test('production modules do not import DEV Mock; Unit contract has no selected',()=>{for(const name of ['useImageBatch','imageBatchApi','imageBatchCore']){const s=readFileSync(`resources/js/composables/v2/imageBatch/${name}.ts`,'utf8');assert.doesNotMatch(s,/from\s+['"][^'"]*DevMock/);}const type=readFileSync('resources/js/types/v2/imageBatch/imageBatch.ts','utf8');assert.doesNotMatch(type,/selected\s*:/);});

for(const method of ['run','copyTask','resolveSourceChange'])test(`${method} holds lock after PUT while server action is pending`,async()=>{
 const {e,client,calls}=fixture();await e.load();e.updateBatchNote('saved first');
 let finish,started;const ready=new Promise(resolve=>{started=resolve;});const original=client[method];
 client[method]=(...args)=>{started();return new Promise(resolve=>{finish=async()=>resolve(await original(...args));});};
 const pending=method==='run'?e.run('full'):method==='copyTask'?e.copyTask():e.keepCurrent('u1');await ready;
 assert.equal(e.dirty.value,false);assert.equal(e.busy.value,true);assert.equal(await e.load(),false);
 e.updateBatchNote('race');assert.equal(e.workspace.value.batch_note,'saved first');
 assert.equal(await e.run('full'),false);assert.equal(await e.copyTask(),false);assert.equal(await e.regenerate('u1'),false);
 await finish();await pending;assert.equal(e.busy.value,false);assert.equal(calls.filter(x=>Array.isArray(x)&&x[0]==='put').length,1);
});
test('post-action GET holds lock and clears local selection only after success',async()=>{
 const {e,client}=fixture();await e.load();e.selectAll();let finish,started;const ready=new Promise(resolve=>{started=resolve;});const original=client.loadWorkspace;
 client.loadWorkspace=()=>{started();return new Promise(resolve=>{finish=async()=>resolve(await original());});};
 const pending=e.run('full');await ready;assert.equal(e.busy.value,true);assert.equal(e.selectedUnitIds.value.size,1);
 assert.equal(await e.load(),false);e.clearSelection();assert.equal(e.selectedUnitIds.value.size,1);
 await finish();await pending;assert.equal(e.selectedUnitIds.value.size,0);assert.equal(e.busy.value,false);
});
test('source API failure retains server status and warning',async()=>{const {e,client}=fixture();await e.load();client.resolveSourceChange=async()=>{throw Error('rejected');};assert.equal(await e.regenerate('u1'),false);assert.equal(e.units.value[0].source_changed,true);assert.equal(e.units.value[0].status,'generated');assert.match(e.runError.value,/rejected/);});
test('clipboard failure is reported without false copied state',async()=>{const {e,client,calls}=fixture();const editor=useImageBatch(1,2,3,client,async()=>{throw Error('clipboard denied');});await editor.load();assert.equal(await editor.copyTask(),false);assert.equal(editor.copied.value,false);assert.match(editor.runError.value,/clipboard denied/);assert.deepEqual(calls,['get','copy']);});
test('runtime patch cannot overwrite system or source fields',async()=>{const {e,calls}=fixture();await e.load();e.updateUnit('u1',{special_requirement:'allowed',status:'approved',source_changed:false,final_copy:'forged',selected:true});assert.equal(e.units.value[0].status,'generated');assert.equal(e.units.value[0].source_changed,true);assert.equal(e.units.value[0].final_copy,'system');assert.equal('selected' in e.units.value[0],false);await e.saveDraft();assert.deepEqual(calls[1][1].units,[{id:'u1',special_requirement:'allowed'}]);});
test('DEV preview loads the production stylesheet for visual acceptance',()=>{assert.match(readFileSync('resources/js/v2-image-batch-preview.ts','utf8'),/import\s+['"]\.\.\/css\/app\.css['"]/);});
