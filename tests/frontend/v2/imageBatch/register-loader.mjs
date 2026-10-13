// 临时测试基建（不提交）：注册 ts-extension-loader.mjs。
import { register } from 'node:module';
register('./ts-extension-loader.mjs', import.meta.url);
