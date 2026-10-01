// Minimal toast utility. A reactive list rendered by components/ToastHost.vue.
import { reactive } from 'vue';

export type ToastType = 'success' | 'error' | 'info';

export interface ToastItem {
  id: number;
  type: ToastType;
  message: string;
}

export const toasts = reactive<ToastItem[]>([]);

let seq = 0;

export function pushToast(type: ToastType, message: string, duration = 2600): void {
  const id = ++seq;
  toasts.push({ id, type, message });
  setTimeout(() => {
    const idx = toasts.findIndex((t) => t.id === id);
    if (idx !== -1) toasts.splice(idx, 1);
  }, duration);
}

export const toast = {
  success: (m: string) => pushToast('success', m),
  error: (m: string) => pushToast('error', m),
  info: (m: string) => pushToast('info', m),
};
