// V2-P3-C04 独立开发预览入口（DEV ONLY）。
//
// 用途：在无 Laravel / Inertia / 后端 API 环境下，本地浏览器预览「待我审核」轻量审核中心 UI。
// 数据为固定示例（明确标注 DEV），决策使用 MOCK providers；不会请求 /api，
// 也不属于生产 fallback —— 正式页面必须走 resources/js/pages/V2/Review/ReviewCenter.vue。
//
// 启动：npm run dev 后访问 /v2-review-preview.html
import { createApp } from 'vue';
import ReviewDevPreviewApp from './components/V2/Review/ReviewDevPreviewApp.vue';

createApp(ReviewDevPreviewApp).mount('#app');
