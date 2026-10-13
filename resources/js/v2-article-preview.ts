// V2-P2-C02 独立开发预览入口（DEV ONLY）。
//
// 用途：在无 Laravel / Inertia / 后端 API 环境下，本地浏览器预览图文文章编辑器 UI。
// 数据为固定示例（明确标注 DEV），AI 导入使用 MOCK providers；不会请求 /api，
// 也不属于生产 fallback —— 正式页面必须走 resources/js/pages/V2/Article/ArticleEditor.vue。
//
// 启动：npm run dev 后访问 /v2-article-preview.html
import { createApp } from 'vue';
import ArticleDevPreviewApp from './components/V2/Article/ArticleDevPreviewApp.vue';

createApp(ArticleDevPreviewApp).mount('#app');
