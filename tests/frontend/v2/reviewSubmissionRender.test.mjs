import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,existsSync} from 'node:fs';
import {build} from 'esbuild';
import {parse,compileScript} from '@vue/compiler-sfc';
import {createRenderer,reactive,h,nextTick} from 'vue';
const vueUrl=new URL('../../../node_modules/vue/dist/vue.runtime.esm-bundler.js',import.meta.url).href;
const bundle=await build({entryPoints:['resources/js/components/V2/Review/ReviewSubmission.vue'],bundle:true,write:false,format:'esm',external:['vue'],plugins:[{name:'vue',setup(b){
 b.onLoad({filter:/\.vue$/},a=>({loader:'ts',contents:compileScript(parse(readFileSync(a.path,'utf8'),{filename:a.path}).descriptor,{id:a.path,inlineTemplate:true,fs:{fileExists:existsSync,readFile:p=>readFileSync(p,'utf8')}}).content}));
 b.onResolve({filter:/reviewSubmissionApi$|api\/errors$|@inertiajs\/vue3/},a=>({path:a.path,namespace:'mock'}));
 b.onLoad({filter:/.*/,namespace:'mock'},a=>({loader:'js',contents:a.path.endsWith('reviewSubmissionApi')?'export const reviewSubmissionApi={load:(...a)=>globalThis.__submissionClient.load(...a),submit:(...a)=>globalThis.__submissionClient.submit(...a)};':a.path.endsWith('errors')?'export const firstErrorMessage=e=>e.message;':'import {h} from "vue"; export const Link={props:["href"],setup:(p,{slots})=>()=>h("a",{href:p.href},slots.default?.())};'}));
}}]});
const {default:Controls}=await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text.replaceAll('from "vue"',`from ${JSON.stringify(vueUrl)}`)).toString('base64'));
const renderer=createRenderer({createElement:tag=>({tag,children:[],props:{}}),insert:(c,p)=>{c.parent=p;p.children.push(c);},remove:n=>{if(n.parent)n.parent.children=n.parent.children.filter(c=>c!==n);},setElementText:(n,t)=>n.text=t,createText:text=>({text}),createComment:text=>({text}),setText:(n,t)=>n.text=t,patchProp:(n,k,o,v)=>n.props[k]=v,parentNode:n=>n.parent,nextSibling:()=>null,setComment(){}});
const flush=async()=>{await Promise.resolve();await Promise.resolve();await nextTick();};
function mount(target,client){globalThis.__submissionClient=client;const props=reactive({projectId:1,itemId:2,branchId:3,target,dirty:false,busy:false,ready:true});const root={children:[]};const app=renderer.createApp({setup:()=>()=>h(Controls,props)});app.mount(root);const nodes=()=>{const out=[];function walk(n){out.push(n);for(const c of n.children??[])walk(c);}walk(root);return out;};return {props,app,nodes,text:()=>nodes().map(n=>n.text??'').join(' '),button:text=>nodes().find(n=>n.tag==='button'&&n.text?.includes(text))};}
for(const target of ['image_post_copy','article_copy','video_production','image_batch'])test(`${target}: initial, dirty, saved, submitting, pending and reload render`,async()=>{
 let pending=false,finish;const calls=[];const client={async load(){calls.push('get');return {pending,review:pending?{id:8,status:'pending'}:null};},submit(){calls.push('post');return new Promise(r=>finish=()=>{pending=true;r({created:true,review:{id:8,status:'pending'}});});}};
 const x=mount(target,client);await flush();assert.equal(x.button('提交审核').props.disabled,false);
 x.props.dirty=true;await flush();assert.equal(x.button('提交审核').props.disabled,true);assert.ok(x.text().includes('请先保存当前修改，再提交审核。'));
 x.props.dirty=false;x.props.busy=true;await flush();assert.equal(x.button('提交审核').props.disabled,true);
 x.props.busy=false;await flush();assert.equal(x.button('提交审核').props.disabled,false);
 const request=x.button('提交审核').props.onClick();await flush();assert.equal(x.button('提交中').props.disabled,true);finish();await request;await flush();
 assert.ok(x.text().includes('审核中'));assert.equal(x.nodes().find(n=>n.tag==='a').props.href,'/projects/1/reviews');
 // 保存成功（dirty 由 true → false）会按当前保存版本重算审核状态（额外一次 GET），
 // 再是提交 POST 与提交后的刷新 GET。
 assert.deepEqual(calls,['get','get','post','get']);x.app.unmount();
 const reload=mount(target,client);await flush();assert.ok(reload.text().includes('审核中'));reload.app.unmount();
});
test('initial error retries GET; failed POST and failed confirmation GET remain visible',async()=>{
 let failLoad=true,failSubmit=true;const client={async load(){if(failLoad)throw Error('load failed');return {pending:false,review:null};},async submit(){if(failSubmit)throw Error('submit failed');failLoad=true;return {created:true,review:{id:8,status:'pending'}};}};
 const x=mount('article_copy',client);await flush();assert.ok(x.text().includes('load failed'));assert.equal(x.button('提交审核').props.disabled,true);
 failLoad=false;await x.button('重试').props.onClick();await flush();assert.equal(x.button('提交审核').props.disabled,false);
 await x.button('提交审核').props.onClick();await flush();assert.ok(x.text().includes('submit failed'));
 await x.button('重试').props.onClick();failSubmit=false;await x.button('提交审核').props.onClick();await flush();assert.ok(x.text().includes('load failed'));assert.ok(!x.text().includes('审核中'));x.app.unmount();
});
