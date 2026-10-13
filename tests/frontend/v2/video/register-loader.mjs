// 测试基建：注册 ts-extension-loader.mjs。
import { register } from 'node:module';
register('./ts-extension-loader.mjs', import.meta.url);
