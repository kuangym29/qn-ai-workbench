import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
const bundle = await build({entryPoints:['resources/js/composables/v2/review/useReviewSubmission.ts'],bundle:true,write:false,format:'esm',platform:'browser',plugins:[{name:'transport',setup(b){b.onResolve({filter:/reviewSubmissionApi$|api\/errors$/},a=>({path:a.path,namespace:'mock'}));b.onLoad({filter:/.*/,namespace:'mock'},a=>({contents:a.path.endsWith('reviewSubmissionApi')?'export const reviewSubmissionApi={};':'export const firstErrorMessage=e=>e.message;',loader:'js'}));}}]});
const {useReviewSubmission}=await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text).toString('base64'));
const review={id:9,status:'pending',review_target_type:'article_copy'};
test('loads pending, submits empty command then GET, and blocks dirty or duplicate clicks',async()=>{
 let dirty=false, pending=false, finish;const calls=[];
 const client={async load(...args){calls.push(['get',...args]);return {pending,review:pending?review:null};},async submit(...args){calls.push(['post',...args]);await new Promise(r=>finish=r);pending=true;return {created:true,review};}};
 const s=useReviewSubmission(()=>({projectId:1,itemId:2,branchId:3,target:'article_copy'}),()=>dirty,client);
 await s.load();assert.equal(s.pending.value,false);dirty=true;assert.equal(await s.submit(),false);assert.equal(calls.length,1);
 dirty=false;const first=s.submit();assert.equal(s.submitting.value,true);assert.equal(await s.submit(),false);finish();await first;
 assert.deepEqual(calls.map(c=>c[0]),['get','post','get']);assert.equal(calls[1].length,5);assert.equal(s.pending.value,true);
});
test('error is visible and stale route response never replaces current pending',async()=>{
 let branchId=3,finish;const client={load(p,i,b){return b===3?new Promise(r=>finish=r):Promise.resolve({pending:false,review:null});},async submit(){throw Error('unavailable');}};
 const s=useReviewSubmission(()=>({projectId:1,itemId:2,branchId,target:'article_copy'}),()=>false,client);
 const old=s.load();branchId=4;await s.load();finish({pending:true,review});await old;assert.equal(s.pending.value,false);
 assert.equal(await s.submit(),false);assert.equal(s.error.value,'unavailable');assert.equal(s.submitting.value,false);
});
test('readiness or save busy blocks submit; GET failure after POST never fabricates pending',async()=>{
 let blocked=true,fail=false;const calls=[];const client={async load(){calls.push('get');if(fail)throw Error('reload failed');return {pending:false,review:null};},async submit(){calls.push('post');fail=true;return {created:true,review};}};
 const s=useReviewSubmission(()=>({projectId:1,itemId:2,branchId:3,target:'article_copy'}),()=>blocked,client);
 await s.load();assert.equal(await s.submit(),false);assert.deepEqual(calls,['get']);
 blocked=false;assert.equal(await s.submit(),false);assert.equal(s.pending.value,false);assert.equal(s.error.value,'reload failed');assert.deepEqual(calls,['get','post','get']);
});
