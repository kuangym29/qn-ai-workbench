// V2 图片批次 —— 独立 DEV 预览入口（仅开发预览，不进入生产路由）。
// 生产正式页面为 pages/V2/ImageBatch/ImageBatchEditor.vue。
import { createApp } from 'vue';
import '../css/app.css';
import ImageBatchDevPreviewApp from './components/V2/ImageBatch/ImageBatchDevPreviewApp.vue';

createApp(ImageBatchDevPreviewApp).mount('#v2-image-batch-preview');
