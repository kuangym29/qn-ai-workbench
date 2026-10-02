<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
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
import Badge from '../components/Badge.vue';
import ToastHost from '../components/ToastHost.vue';

const page = usePage();
const isProjectsActive = computed(() => String(page.component).startsWith('Projects'));
const isColumnsActive = computed(() => String(page.component).startsWith('Columns'));

const projects = ref<Project[]>([]);

onMounted(async () => {
  // Any project can be the target of the switcher, so load the full list once.
  projects.value = await projectsApi.list();
  // Server is the source of truth: hydrate from the shared prop on first render.
  hydrateFromServer((page.props.currentProject as Project | null) ?? null);
});

// Keep the local mirror in sync with the server session on every navigation.
watch(
  () => page.props.currentProject,
  (project) => hydrateFromServer((project as Project | null) ?? null),
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
    // If we're inside a column view, follow the switch into the new project's columns.
    if (isColumnsActive.value) router.visit(`/projects/${selected.id}/columns`);
  } catch {
    toast.error('切换项目失败，请重试');
  }
}
</script>

<template>
  <div class="flex h-screen bg-slate-100">
    <!-- Sidebar -->
    <aside class="flex w-60 flex-col bg-slate-900 text-slate-300">
      <div class="border-b border-slate-800 px-5 py-4">
        <p class="text-sm font-semibold text-white">QN AI 内容工作台</p>
        <p class="text-xs text-slate-500">Lite V1.0</p>
      </div>

      <nav class="flex-1 space-y-1 px-3 py-4">
        <Link href="/projects" :class="navClass(isProjectsActive)">项目</Link>
        <button type="button" :class="navClass(isColumnsActive)" @click="goToColumns">小栏目</button>

        <!--
          内容流程：选题 / 篇目 / 制作与渠道都已实现（DEV-W05 起 ProductionTask 与
          ChannelTask 也有正式 API）。它们没有各自独立的全局列表路由，必须从上级层级
          进入，因此这里只做说明性展示，不提供会误导的 Sidebar 链接。
        -->
        <p class="px-3 pb-1 pt-4 text-xs uppercase tracking-wider text-slate-600">内容流程</p>
        <span :class="navClass(false, false)"
          >选题 <Badge variant="muted" label="按栏目进入" /></span
        >
        <span :class="navClass(false, false)"
          >篇目 <Badge variant="muted" label="按选题进入" /></span
        >
        <span :class="navClass(false, false)"
          >制作与渠道 <Badge variant="muted" label="按篇目进入" /></span
        >
      </nav>
    </aside>

    <!-- Main column -->
    <div class="flex min-w-0 flex-1 flex-col">
      <!-- Topbar: current project area + switcher -->
      <header
        class="flex items-center justify-between border-b border-slate-200 bg-white px-6 py-3"
      >
        <div class="flex items-center gap-3">
          <span class="text-sm text-slate-500">当前项目</span>
          <select
            :value="projectContext.current?.id ?? ''"
            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 focus:border-slate-400 focus:outline-none"
            @change="onSwitch"
          >
            <option value="">未选择项目</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <span v-if="projectContext.current" class="text-xs text-slate-400">
            已选：{{ projectContext.current.name }}
          </span>
        </div>
        <span
          class="rounded-full px-2.5 py-0.5 text-xs font-medium"
          :class="USE_MOCK ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
        >
          {{ USE_MOCK ? 'Mock 数据模式' : '真实接口模式' }}
        </span>
      </header>

      <!-- Page content -->
      <main class="flex-1 overflow-y-auto px-6 py-6">
        <slot />
      </main>
    </div>

    <ToastHost />
  </div>
</template>
