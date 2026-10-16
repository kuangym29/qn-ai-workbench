import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
const bundle = await build({ entryPoints: ['resources/js/composables/v2/useImagePostEditor.ts'], bundle: true, write: false, format: 'esm', platform: 'browser', plugins: [{name:'isolate-transport',setup(b){
 b.onResolve({filter:/imagePostApi$|api\/errors$|ui\/toast$/},a=>({path:a.path,namespace:'test'}));
 b.onLoad({filter:/.*/,namespace:'test'},a=>({contents:a.path.endsWith('imagePostApi')?'export const imagePostApi = {};':a.path.endsWith('errors')?'export const firstErrorMessage = e => e.message;':'export const toast={success(){},error(){}};',loader:'js'}));
}}] });
const {useImagePostEditor} = await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text).toString('base64'));
function fixture() {
 const calls=[];let text='old';
 const p={item:{changed:false,fields:[]},pages:{added:[],modified:[{page_no:4,page_type:'content',changes:[{field:'final_copy',before:'old',after:'new'}]}],unchanged:[]},warnings:[]};
 const api={async loadWorkspace(){calls.push('get');return {item:{id:1,title:'t',core_message:'c',story_line:''},pages:[{page_no:4,page_type:'content',final_copy:text}],branch:{id:3}};},async preview(...args){calls.push(['preview',args[3]]);return p;},async apply(...args){calls.push(['apply',args[3]]);text='new';},async save(){calls.push('put');return {warnings:[]};}};
 return {e:useImagePostEditor(1,2,3,api),calls,api};
}
test('review dirty guard compares saved nested page and shared item fields, then clears after save GET',async()=>{
 const {e}=fixture();await e.load();assert.equal(e.dirty.value,false);
 e.pages.value[0].final_copy='edited';assert.equal(e.dirty.value,true);
 e.pages.value[0].final_copy='old';assert.equal(e.dirty.value,false);
 e.item.value.core_message='changed';assert.equal(e.dirty.value,true);
 await e.save();assert.equal(e.dirty.value,false);
});
test('review stays dirty if PUT succeeds but GET fails',async()=>{
 const {e,api}=fixture();await e.load();e.item.value.story_line='modified';
 api.loadWorkspace=async()=>{throw Error('refresh failed');};await e.save();assert.equal(e.dirty.value,true);
});
test('preview and cancellation never mutate formal pages or call apply',async()=>{
 const {e,calls}=fixture();await e.load();e.pasteText.value='P4 raw';await e.runParse();
 assert.equal(e.pages.value[0].final_copy,'old');e.closeImport();
 assert.equal(e.preview.value,null);assert.deepEqual(calls,['get',['preview','P4 raw']]);
});
test('confirm sends original raw to backend then reloads formal data',async()=>{
 const {e,calls}=fixture();await e.load();e.pasteText.value='P4 raw';await e.runParse();e.pasteText.value='edited after preview';await e.confirmImport();
 assert.deepEqual(calls,['get',['preview','P4 raw'],['apply','P4 raw'],'get']);assert.equal(e.pages.value[0].final_copy,'new');assert.equal(e.phase.value,'idle');
});
test('failed apply keeps preview and retry available without local mutation',async()=>{
 const {e}=fixture();await e.load();e.pasteText.value='P4 raw';await e.runParse();
 // The transport failure is injected on a separately constructed editor.
 const api={async loadWorkspace(){return {item:{id:1},pages:[],branch:{id:3}};},async preview(){return e.preview.value;},async apply(){throw Error('network');}};
 const bad=useImagePostEditor(1,2,3,api);bad.pasteText.value='raw';await bad.runParse();await bad.confirmImport();
 assert.equal(bad.phase.value,'preview');assert.equal(bad.applyError.value,'network');assert.equal(bad.applying.value,false);
});

test('successful apply followed by GET failure closes preview to avoid duplicate apply',async()=>{
 const {e,api,calls}=fixture();await e.load();e.pasteText.value='P4 raw';await e.runParse();
 api.loadWorkspace=async()=>{calls.push('get failed');throw Error('reload unavailable');};
 await e.confirmImport();assert.equal(e.phase.value,'idle');assert.equal(e.preview.value,null);assert.equal(e.loadError.value,'reload unavailable');
 await e.confirmImport();assert.equal(calls.filter(c=>Array.isArray(c)&&c[0]==='apply').length,1);
});
test('manual save excludes fixed cover and reloads authoritative pages',async()=>{
 const {e,api,calls}=fixture();await e.load();e.pages.value.push({page_no:5,page_type:'fixed_back_cover',final_copy:'fixed'});
 api.save=async(...args)=>{calls.push(['put',args[3]]);return {warnings:[]};};
 await e.save();const input=calls.find(c=>Array.isArray(c)&&c[0]==='put')[1];
 assert.deepEqual(input.pages.map(p=>p.page_no),[4]);assert.equal(calls.at(-1),'get');
});

test('unsaved manual edits prevent preview; saving and GET allow preview again',async()=>{
 const {e,calls}=fixture();await e.load();e.item.value.core_message='manual edit';e.pasteText.value='P4 raw';
 await e.runParse();assert.equal(e.parseError.value,'请先保存手动修改，再导入 AI 内容。');
 assert.deepEqual(calls,['get']);
 await e.save();await e.runParse();assert.equal(e.phase.value,'preview');assert.equal(calls.filter(c=>Array.isArray(c)&&c[0]==='preview').length,1);
});

test('pending preview prevents page updates and manual save races',async()=>{
 const {e,api,calls}=fixture();await e.load();e.pasteText.value='raw';
 let finish;api.preview=()=>new Promise(resolve=>{finish=resolve;});
 const pending=e.runParse();assert.equal(e.phase.value,'parsing');
 e.updatePage({...e.pages.value[0],final_copy:'race'});assert.equal(e.pages.value[0].final_copy,'old');
 assert.equal(await e.save(),false);assert.deepEqual(calls,['get']);
 finish({item:{changed:false,fields:[]},pages:{added:[],modified:[],unchanged:[]},warnings:[]});await pending;
});
