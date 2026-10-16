import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { readFileSync, existsSync } from 'node:fs';
async function bundle(path, isolate) {
 const r=await build({entryPoints:[path],bundle:true,write:false,format:'esm',platform:'browser',plugins:[{name:'isolate',setup(b){b.onResolve({filter:/api\/http$|api\/errors$|ui\/toast$/},a=>({path:a.path,namespace:'isolated'}));b.onLoad({filter:/.*/,namespace:'isolated'},a=>({contents:a.path.endsWith('http')?'export const http={};':a.path.endsWith('errors')?'export const firstErrorMessage=e=>e.message;':'export const toast={success(){},error(){}};',loader:'js'}));}}]});
 return import('data:text/javascript;base64,'+Buffer.from(r.outputFiles[0].text).toString('base64'));
}
const {createArticleClient}=await bundle('resources/js/composables/v2/article/articleApi.ts');
const {useArticleEditor}=await bundle('resources/js/composables/v2/article/useArticleEditor.ts');
const workspace={item:{id:2,title:'Title',core_message:null,story_line:null},branch:{id:3,channel:'wechat_official',format:'article'},article:null};
test('API handles raw GET article:null, PUT/apply status then GET, and raw structured preview',async()=>{
 const calls=[];const diff={item:{changed:false,fields:[]},article:{added:[],modified:[{field:'image_suggestions',before:[],after:[{position:1,description:'new'}]}],unchanged:[{field:'headline'}]},warnings:[]};
 const client=createArticleClient({async get(url){calls.push(['get',url]);return {data:workspace};},async put(url,input){calls.push(['put',url,input]);return {data:{data:{status:'saved'}}};},async post(url,input){calls.push(['post',url,input]);return {data:url.endsWith('preview')?diff:{data:{status:'applied'}}};}});
 const base='/api/projects/1/items/2/branches/3/article';
 assert.equal((await client.loadWorkspace(1,2,3)).article,null);
 assert.deepEqual(await client.preview(1,2,3,'RAW'),diff);
 assert.equal((await client.save(1,2,3,{headline:'h',summary:null,body:null,image_suggestions:null,cover_suggestion:null})).article,null);
 assert.deepEqual(calls.at(-2)[2].image_suggestions,[]);
 assert.equal((await client.apply(1,2,3,'RAW')).article,null);
 assert.deepEqual(calls.map(c=>c[0]),['get','post','put','get','post','get']);
 assert.equal(calls[1][1],base+'/preview');assert.deepEqual(calls[1][2],{raw:'RAW'});
});
function fixture(){const calls=[];const client={async loadWorkspace(){calls.push('get');return structuredClone(workspace);},async save(p,i,b,input){calls.push(['put',input]);return {...workspace,article:input};},async preview(p,i,b,raw){calls.push(['preview',raw]);return {item:{changed:false,fields:[]},article:{added:[{field:'headline',after:'AI'}],modified:[],unchanged:[]},warnings:[]};},async apply(p,i,b,raw){calls.push(['apply',raw]);return {...workspace,article:{headline:'AI',image_suggestions:[]}};}};const e=useArticleEditor(1,2,3,{client});return {e,client,calls};}
test('review dirty tracks all editable fields and clears only on successful server response',async()=>{
 const {e,client}=fixture();await e.load();assert.equal(e.dirty.value,false);
 e.updateField('headline','changed');assert.equal(e.dirty.value,true);e.updateField('headline','');assert.equal(e.dirty.value,false);
 e.addImage({position:1,description:'new',note:null});assert.equal(e.dirty.value,true);
 const save=client.save;client.save=async()=>{throw Error('GET refresh failed');};assert.equal(await e.save(),false);assert.equal(e.dirty.value,true);
 client.save=save;await e.save();assert.equal(e.dirty.value,false);
});
test('empty article opens safely and manual save uses formal fields',async()=>{const {e,calls}=fixture();await e.load();assert.equal(e.item.value.title,'Title');assert.equal(e.fields.value.headline,null);e.updateField('headline','Manual');assert.equal(await e.save(),true);assert.equal(e.fields.value.headline,'Manual');assert.equal(calls[1][0],'put');});
test('cancel is read-only and apply uses the exact previewed raw input',async()=>{const {e,calls}=fixture();await e.load();e.pasteText.value='first';await e.runPreview();e.closeImport();assert.deepEqual(calls,['get',['preview','first']]);e.pasteText.value='reviewed';await e.runPreview();e.pasteText.value='changed without preview';await e.confirmImport();assert.deepEqual(calls.at(-1),['apply','reviewed']);assert.equal(e.fields.value.headline,'AI');});
test('save and preview busy reject duplicate actions and edits',async()=>{const {e,client,calls}=fixture();await e.load();let finish;client.save=()=>new Promise(r=>finish=r);const pending=e.save();e.updateField('headline','race');assert.equal(e.fields.value.headline,null);assert.equal(await e.save(),false);e.pasteText.value='raw';await e.runPreview();assert.deepEqual(calls,['get']);finish({...workspace,article:null});await pending;});
test('production contains no DEV Mock or alternate parser/apply',()=>{for(const p of ['articleApi','articleCore','useArticleEditor'])assert.doesNotMatch(readFileSync(`resources/js/composables/v2/article/${p}.ts`,'utf8'),/import[^;]*DevMock/);assert.equal(existsSync('resources/js/composables/v2/article/articleDevMock.ts'),false);});
