import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {build} from 'esbuild';
import {parse,compileScript} from '@vue/compiler-sfc';
import {createRenderer} from 'vue';
const bundle=await build({entryPoints:['resources/js/components/V2/ImageBatch/ImageBatchWorkspaceBody.vue'],bundle:true,write:false,format:'esm',external:['vue'],plugins:[{name:'vue',setup(b){b.onLoad({filter:/\.vue$/},a=>{const {descriptor}=parse(readFileSync(a.path,'utf8'));return {contents:compileScript(descriptor,{id:a.path,inlineTemplate:true}).content,loader:'ts'};});}}]});
// Vue is imported from the test's package resolution, so the component shares the renderer runtime.
// Resolve Vue from the project root rather than the data URL.
const vueUrl=new URL('../../../../node_modules/vue/dist/vue.runtime.esm-bundler.js',import.meta.url).href;
const {default:Body}=await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text.replaceAll('from "vue"',`from ${JSON.stringify(vueUrl)}`)).toString('base64'));
const renderer=createRenderer({createElement:tag=>({tag,children:[],props:{}}),insert:(c,p)=>p.children.push(c),remove(){},setElementText:(n,t)=>n.text=t,createText:text=>({text}),createComment:text=>({text}),setText:(n,t)=>n.text=t,patchProp:(n,k,o,v)=>n.props[k]=v,parentNode:()=>null,nextSibling:()=>null,setComment(){}});
function mount(busy=false){const root={children:[]};let saves=0;renderer.createApp(Body,{contentTitle:'T',sourceLabel:'S',batchInstruction:'',batchNote:null,assets:[],units:[],history:[],loading:false,loadError:'',runPhase:'idle',runError:'',copied:false,historyOpen:false,allSelected:false,changedCount:0,busy,dirty:true,selectedUnitIds:new Set(),onSaveDraft:()=>saves++}).mount(root);const nodes=[];function walk(n){nodes.push(n);for(const c of n.children??[])walk(c);}walk(root);return {button:nodes.find(n=>n.tag==='button'&&n.text==='保存草稿'),saves:()=>saves};}
test('draft can be saved without queuing a generation run',()=>{const x=mount();assert.ok(x.button,'a standalone save action must exist');x.button.props.onClick();assert.equal(x.saves(),1);});
test('standalone save is disabled while an action is busy',()=>{assert.equal(mount(true).button?.props.disabled,true);});
