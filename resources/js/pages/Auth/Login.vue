<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import type { AuthUser } from '../../api/auth';
import { authApi, isCsrfOrSessionExpired, isUnauthenticated } from '../../api/auth';
import { extractFieldErrors } from '../../api/errors';

// Auth Lite login page — frontend half only (DEV-W11-AUTH-LITE-UI-PREP).
//
// Scope is deliberately tiny: email, password, submit. No registration, no password
// reset, no social login, no marketing page — Lite V1.0 is a fixed set of users and
// the backend is the only authority on whether a session exists.
//
// Two rules shape the implementation:
//
// 1. Nothing about the session is stored client-side. The Laravel session cookie is
//    the credential, so there is no token to keep and therefore nothing to persist.
//    A reload starts from an unknown auth state and asks the server again.
//
// 2. A failed submit never destroys what the user typed. The email and password stay
//    in the form across 422, 419 and network errors; only the server-provided field
//    message is added. The password is sent once and is never logged or echoed.
//
// This page performs no auth state management of its own: after a successful login
// it hands the returned user to the caller and defers navigation, because the route
// that guards "where do we go next" belongs to the backend task.
const props = defineProps<{
  /** Where to go after a successful login. Supplied by the backend route. */
  redirectTo?: string;
}>();

const emit = defineEmits<{
  (e: 'authenticated', user: AuthUser): void;
}>();

type Status = 'idle' | 'submitting';

const status = ref<Status>('idle');
const email = ref('');
const password = ref('');
// Server-authored messages only: errors.email for 422, plus one banner for the
// cases that are not tied to a single field.
const fieldErrors = ref<{ email?: string; password?: string }>({});
const bannerError = ref('');

const submitting = computed(() => status.value === 'submitting');

function resetErrors(): void {
  fieldErrors.value = {};
  bannerError.value = '';
}

async function submit(): Promise<void> {
  if (submitting.value) return;
  resetErrors();
  status.value = 'submitting';
  try {
    const user = await authApi.login({
      email: email.value.trim(),
      password: password.value,
    });
    // The server set the session cookie; the user object is the confirmation.
    password.value = '';
    emit('authenticated', user);
    if (props.redirectTo) {
      router.visit(props.redirectTo);
    }
  } catch (e) {
    // 422: attach the message to the field the backend blamed, keep the input.
    const errors = extractFieldErrors(e);
    fieldErrors.value = {
      email: errors.email?.[0],
      password: errors.password?.[0],
    };
    if (isUnauthenticated(e)) {
      bannerError.value = '邮箱或密码不正确。';
    } else if (isCsrfOrSessionExpired(e)) {
      // The CSRF token or the session went stale; a reload gets a fresh one.
      bannerError.value = '会话已过期，请刷新页面后重试。';
    } else if (Object.keys(fieldErrors.value).length === 0) {
      bannerError.value = '登录失败，请稍后重试。';
    }
  } finally {
    status.value = 'idle';
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center bg-slate-100 px-4 py-12">
    <div class="w-full max-w-sm">
      <div class="rounded-lg border border-slate-200 bg-white p-6">
        <p class="text-xs font-semibold uppercase tracking-widest text-emerald-700">
          QN AI 内容工作台
        </p>
        <h1 class="mt-2 text-lg font-semibold text-slate-900">登录</h1>
        <p class="mt-1 text-sm text-slate-500">使用工作台账号继续。</p>

        <form class="mt-6 space-y-4" @submit.prevent="submit">
          <div>
            <label for="auth-email" class="block text-sm font-medium text-slate-700">邮箱</label>
            <input
              id="auth-email"
              v-model="email"
              type="email"
              name="email"
              autocomplete="username"
              required
              :disabled="submitting"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:border-slate-900 focus:outline-none disabled:opacity-50"
            />
            <p v-if="fieldErrors.email" class="mt-1 text-xs text-rose-600">
              {{ fieldErrors.email }}
            </p>
          </div>

          <div>
            <label for="auth-password" class="block text-sm font-medium text-slate-700">
              密码
            </label>
            <input
              id="auth-password"
              v-model="password"
              type="password"
              name="password"
              autocomplete="current-password"
              required
              :disabled="submitting"
              class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 focus:border-slate-900 focus:outline-none disabled:opacity-50"
            />
            <p v-if="fieldErrors.password" class="mt-1 text-xs text-rose-600">
              {{ fieldErrors.password }}
            </p>
          </div>

          <p
            v-if="bannerError"
            class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"
          >
            {{ bannerError }}
          </p>

          <button
            type="submit"
            :disabled="submitting"
            class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {{ submitting ? '登录中…' : '登录' }}
          </button>
        </form>
      </div>

      <p class="mt-4 text-center text-xs text-slate-400">
        Lite V1.0 暂不提供注册与找回密码，如需开通请联系管理员。
      </p>
    </div>
  </main>
</template>
