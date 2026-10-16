import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
const result = await build({entryPoints:['resources/js/composables/v2/contentBranchLinks.ts'],bundle:true,write:false,format:'esm',platform:'node'});
const { contentBranchLinks } = await import('data:text/javascript;base64,'+Buffer.from(result.outputFiles[0].text).toString('base64'));
test('all three formal editors and image production coexist',()=>{
 const base='/projects/1/content/2/branches/3';
 assert.deepEqual(contentBranchLinks(1,2,{id:3,channel:'wechat_official',format:'article'}),{editorHref:base+'/article',editorLabel:'编辑图文文章'});
 assert.deepEqual(contentBranchLinks(1,2,{id:3,channel:'wechat_channels',format:'video'}),{editorHref:base+'/video',editorLabel:'编辑视频制作'});
 assert.deepEqual(contentBranchLinks(1,2,{id:3,channel:'wechat_official',format:'image_post'}),{editorHref:base+'/image-post',editorLabel:'编辑图片文章',productionHref:base+'/image-batch'});
});
test('unsupported channels never receive another channel editor',()=>{
 assert.deepEqual(contentBranchLinks(1,2,{id:3,channel:'xiaohongshu',format:'article'}),{});
 assert.deepEqual(contentBranchLinks(1,2,{id:3,channel:'wechat_official',format:'video'}),{});
});
