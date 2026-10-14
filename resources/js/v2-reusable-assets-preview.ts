// V2-P3-C06 独立开发预览入口（DEV ONLY）。
//
// 用途：在无 Laravel / Inertia / 后端 API 环境下，本地浏览器预览人物 / 场景复用索引 UI。
// 数据为固定示例（内存 Mock，仅 GET）；「准备建立」「查看」仅 toast 占位；不会请求 /api。
// 也不属于生产 fallback —— 正式页面必须走 resources/js/pages/V2/ReusableAssets/ReusableAssetIndex.vue。
//
// 启动：npm run dev 后访问 /v2-reusable-assets-preview.html
import '../css/app.css';
import { createApp } from 'vue';
import ReusableAssetDevPreviewApp from './components/V2/ReusableAssets/ReusableAssetDevPreviewApp.vue';

createApp(ReusableAssetDevPreviewApp).mount('#app');
