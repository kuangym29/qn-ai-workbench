import { http } from '../../api/http';
import { createImagePostClient } from './imagePostCore';
export const imagePostApi = createImagePostClient(http);
