// V2-P3-C03 独立开发预览入口（DEV ONLY）。
//
// 用途：在无 Laravel / Inertia / 后端 API 环境下，本地浏览器预览视频号视频制作 UI。
// 数据为固定示例（明确标注 DEV），保存与动作按钮为内存/提示；不会请求 /api，
// 也不属于生产 fallback —— 正式页面必须走 resources/js/pages/V2/Video/VideoEditor.vue。
//
// 启动：npm run dev 后访问 /v2-video-preview.html
import { createApp } from 'vue';
import VideoDevPreviewApp from './components/V2/Video/VideoDevPreviewApp.vue';

createApp(VideoDevPreviewApp).mount('#app');
