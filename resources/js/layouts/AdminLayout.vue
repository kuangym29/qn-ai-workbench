<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';

// Mobile drawer state. On desktop (md+) the sidebar is a static flex child and
// this flag is ignored (the md: variants force it visible/in-flow). On mobile the
// sidebar is an off-canvas drawer toggled by the hamburger button; a backdrop and
// ESC also close it. No third-party drawer library is used.
const sidebarOpen = ref(false);
const sidebarElement = ref<HTMLElement | null>(null);
const menuButton = ref<HTMLButtonElement | null>(null);
const headerElement = ref<HTMLElement | null>(null);
const headerHeight = ref(0);
let headerObserver: ResizeObserver | null = null;
async function toggleSidebar(): Promise<void> {
  if (sidebarOpen.value) { closeSidebar(); return; }
  sidebarOpen.value = true;
  await nextTick();
  sidebarElement.value?.querySelector<HTMLElement>('button:not([disabled]), a[href]')?.focus();
}
function closeSidebar(): void {
  const wasOpen = sidebarOpen.value;
  sidebarOpen.value = false;
  if (wasOpen) void nextTick(() => menuButton.value?.focus());
}
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') closeSidebar();
}
import { Link, router, usePage } from '@inertiajs/vue3';
import type { Project } from '../api/types';
import { USE_MOCK } from '../api/config';
import { projectsApi } from '../api/projects';
import {
  projectContext,
  hydrateFromServer,
  selectProject,
  setCurrentProject,
} from '../stores/projectContext';
import { toast } from '../ui/toast';
import { authState, handleAuthFailure, loadCurrentUser, logout, returnToLogin } from '../stores/auth';
import { isCsrfOrSessionExpired, isUnauthenticated } from '../api/auth';
import { http } from '../api/http';

import ToastHost from '../components/ToastHost.vue';
import AuthUserMenu from '../components/AuthUserMenu.vue';

const page = usePage();
const isProjectsActive = computed(() => String(page.component).startsWith('Projects'));
const isColumnsActive = computed(() => String(page.component).startsWith('Columns'));
const isSourcesActive = computed(() => String(page.component).startsWith('Sources'));

const projects = ref<Project[]>([]);
const workspaceLinks = [
  {path: 'in-progress', label: '进行中'},
  {path: 'inspirations', label: '灵感'},
  {path: 'topics', label: '选题'},
  {path: 'content', label: '内容库'},
  {path: 'review', label: '待我审核'},
];
function goToWorkspace(path: string): void {
  if (!projectContext.current) { toast.info('请先选择一个项目'); router.visit('/projects'); return; }
  router.visit(`/projects/${projectContext.current.id}/${path}`);
}
function workspaceActive(path: string): boolean {
  return new RegExp(`/projects/\\d+/${path}(?:/|$|\\?)`).test(page.url);
}

// The real signed-in user, or null while unknown / signed out. Sourced from
// GET /api/auth/me only -- never hardcoded, never read from storage.
const authUser = computed(() => authState.user);
const logoutFailed = ref(false);

async function onLogout(): Promise<void> {
  try {
    await logout();
    projects.value = [];
    await returnToLogin();
  } catch (error) {
    projects.value = [];
    setCurrentProject(null);
    if (isUnauthenticated(error) || isCsrfOrSessionExpired(error)) {
      await returnToLogin();
    } else {
      // The server may still hold the session. Block the workspace until logout
      // succeeds or the server confirms that the session has expired.
      logoutFailed.value = true;
    }
  }
}

// Any child request that comes back 401 (session gone) or 419 (CSRF stale) sends
// the user to the login page instead of leaving them on a page whose every write
// would now fail. Registering here covers every API call the page tree makes.
let authInterceptor: number | null = null;

onMounted(async () => {
  window.addEventListener('keydown', onKeydown);
  const measureHeader = () => { headerHeight.value = headerElement.value?.getBoundingClientRect().height ?? 0; };
  measureHeader();
  headerObserver = new ResizeObserver(measureHeader);
  if (headerElement.value) headerObserver.observe(headerElement.value);
  authInterceptor = http.interceptors.response.use(
    (response) => response,
    async (error: unknown) => {
      if (await handleAuthFailure(error)) projects.value = [];
      return Promise.reject(error);
    },
  );

  // Who am I? Ask the server. A 401 simply means "nobody is signed in" and leaves
  // the menu hidden rather than breaking the page.
  const user = await loadCurrentUser();
  if (!user) return;
  // Any project can be the target of the switcher, so load the full list once.
  projects.value = await projectsApi.list();
  // Server is the source of truth: hydrate from the shared prop on first render.
  hydrateFromServer((page.props.currentProject as Project | null) ?? null);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown);
  headerObserver?.disconnect();
  if (authInterceptor !== null) http.interceptors.response.eject(authInterceptor);
});

// Keep the local mirror in sync with the server session on every navigation.
watch(
  () => page.props.currentProject,
  (project) => hydrateFromServer((project as Project | null) ?? null),
);

// Any route change should dismiss the mobile drawer so it never lingers over a
// freshly navigated page.
watch(
  () => page.url,
  () => closeSidebar(),
);

function navClass(active: boolean, enabled = true): string {
  const base = 'block w-full rounded-md px-3 py-2 text-left text-sm transition-colors';
  if (!enabled) return `${base} cursor-not-allowed text-slate-600`;
  return active
    ? `${base} bg-slate-800 font-medium text-white`
    : `${base} text-slate-300 hover:bg-slate-800 hover:text-white`;
}

// 小栏目 requires a current project first (项目上下文优先).
function goToColumns(): void {
  if (!projectContext.current) {
    toast.info('请先选择一个项目');
    router.visit('/projects');
    return;
  }
  router.visit(`/projects/${projectContext.current.id}/columns`);
}

// 来源管理 is project scoped: enter only when a project is selected, and never
// auto-select a project from the URL (the server session stays the only authority).
function goToSources(): void {
  if (!projectContext.current) {
    toast.info('请先选择一个项目');
    router.visit('/projects');
    return;
  }
  router.visit(`/projects/${projectContext.current.id}/sources`);
}

// Top switcher: MUST select on the server FIRST, then mirror locally.
// Never just rewrite localStorage. Lite V1.0 has no "clear current project" action, so
// an empty selection is ignored.
async function onSwitch(event: Event): Promise<void> {
  const id = (event.target as HTMLSelectElement).value;
  if (!id) return;
  try {
    const selected = await selectProject(id);
    if (USE_MOCK) setCurrentProject(selected);
    toast.success(`已切换到项目：${selected.name}`);
    // Follow the switch into the new project. Sources is project scoped, so a stale
    // OLD/sources URL would keep showing the previous project's records (and 404 on
    // every write, because the session is already the NEW project).
    const workspace = workspaceLinks.find(link => workspaceActive(link.path));
    if (workspace) router.visit(`/projects/${selected.id}/${workspace.path}`);
    if (isColumnsActive.value) router.visit(`/projects/${selected.id}/columns`);
    if (isSourcesActive.value) router.visit(`/projects/${selected.id}/sources`);
  } catch {
    toast.error('切换项目失败，请重试');
  }
}
</script>

<template>
  <div v-if="logoutFailed" class="flex h-screen items-center justify-center bg-slate-100">
    <div class="rounded-md border border-slate-200 bg-white p-6 text-center">
      <p class="text-sm text-slate-700">退出请求未完成，请重试。</p>
      <button type="button" class="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm text-white" @click="onLogout">
        重试退出
      </button>
    </div>
  </div>
  <div v-else class="flex h-screen bg-slate-100" :style="{ '--mobile-header-height': `${headerHeight}px` }">
    <!-- Sidebar: static flex child on desktop (md+); off-canvas drawer on mobile.
         md: variants force it back into normal flow and always visible, so the
         desktop layout is unchanged. The mobile state (translate) is driven by
         sidebarOpen below. -->
    <aside
      ref="sidebarElement"
      class="fixed inset-y-0 top-[var(--mobile-header-height)] left-0 z-40 flex w-60 flex-col bg-slate-900 text-slate-300 transition-transform md:static md:top-0 md:z-auto md:translate-x-0 md:visible"
      :class="sidebarOpen ? 'translate-x-0 visible' : '-translate-x-full invisible'"
    >
      <div class="border-b border-slate-800 px-5 py-4">
        <p class="text-sm font-semibold text-white">QN AI 内容工作台</p>
        <p class="text-xs text-slate-500">V2 创作工作台</p>
      </div>

      <nav class="flex-1 space-y-1 px-3 py-4" aria-label="主要导航">
        <button v-for="link in workspaceLinks" :key="link.path" type="button" :class="navClass(workspaceActive(link.path))" :aria-current="workspaceActive(link.path) ? 'page' : undefined" @click="goToWorkspace(link.path)">{{ link.label }}</button>
        <p class="px-3 pt-4 text-xs text-slate-500">设置</p>
        <Link href="/projects" :class="navClass(isProjectsActive)">项目设置</Link>
        <button type="button" :class="navClass(isColumnsActive)" @click="goToColumns">栏目设置</button>
        <button type="button" :class="navClass(isSourcesActive)" @click="goToSources">来源管理</button>
      </nav>   </aside>

    <!-- Mobile drawer backdrop: only rendered while open and never on desktop. -->
    <div
      v-if="sidebarOpen"
      class="fixed inset-0 z-30 bg-black/40 md:hidden"
      aria-hidden="true"
      @click="closeSidebar"
    ></div>

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
      <!-- Topbar: current project area + switcher. Wraps on narrow screens so the
           project selector / user area / hamburger never force a horizontal
           scroll; secondary labels collapse below md/sm. -->
      <header
        ref="headerElement"
        class="relative z-50 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-white px-4 py-3 md:px-6"
      >
        <div class="flex min-w-0 items-center gap-2">
          <button
            ref="menuButton"
            type="button"
            class="inline-flex shrink-0 items-center justify-center rounded-md border border-slate-300 p-1.5 text-slate-700 hover:bg-slate-50 md:hidden"
            :aria-expanded="sidebarOpen"
            aria-label="切换导航菜单"
            @click="toggleSidebar"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <span class="hidden shrink-0 text-sm text-slate-500 sm:inline">当前项目</span>
          <select
            :value="projectContext.current?.id ?? ''"
            class="max-w-[7rem] shrink truncate rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-800 focus:border-slate-400 focus:outline-none sm:max-w-[11rem]"
            @change="onSwitch"
          >
            <option value="">未选择项目</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <span v-if="projectContext.current" class="hidden max-w-[6rem] truncate text-xs text-slate-400 md:inline">
            已选：{{ projectContext.current.name }}
          </span>
        </div>
        <div class="flex min-w-0 items-center gap-2 md:gap-3">
          <span
            class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
            :class="USE_MOCK ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
          >
            {{ USE_MOCK ? 'Mock 数据模式' : '真实接口模式' }}
          </span>
          <AuthUserMenu v-if="authUser" :user="authUser" @logout="onLogout" />
        </div>
      </header>

      <!-- Page content -->
      <main class="min-w-0 flex-1 overflow-y-auto px-4 py-6 md:px-6">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
