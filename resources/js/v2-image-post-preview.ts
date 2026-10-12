// V2-P2-C01 独立开发预览入口（DEV ONLY）。
//
// 用途：在无 Laravel / Inertia / 后端 API 环境下，本地浏览器预览图片文章编辑器 UI。
// 数据为固定示例（明确标注 DEV），交互由内存状态驱动；不会请求 /api，
// 也不属于生产 fallback —— 正式页面必须走 resources/js/pages/V2/ImagePostEditor.vue。
//
// 启动：npm run dev 后访问 /v2-image-post-preview.html
import { createApp } from 'vue';
import ImagePostDevPreviewApp from './components/V2/ImagePostDevPreviewApp.vue';

createApp(ImagePostDevPreviewApp).mount('#app');
