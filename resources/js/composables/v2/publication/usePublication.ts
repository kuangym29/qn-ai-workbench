import { computed, ref } from 'vue';
import { firstErrorMessage } from '../../../api/errors';
import { publicationApi } from './publicationApi';
import type { PublicationScope, PublicationState } from './publicationApi';

// Publication composable (P5-I01). Mirrors useReviewSubmission but around the "发布" action.
//
// Guarding rules:
//  - `blocked()` (dirty / busy / not-ready editor) prevents publish, exactly like submit.
//  - The server-computed `can_publish` (exact approved version, not yet published) gates the
//    button; if false we surface `reason`.
//  - A stale route response never replaces the current state (revision + key guard).
export function usePublication(
  scope: () => PublicationScope,
  blocked: () => boolean,
  client = publicationApi,
) {
  const state = ref<PublicationState | null>(null);
  const loading = ref(false);
  const publishing = ref(false);
  const error = ref('');
  let revision = 0;
  const key = () => JSON.stringify(scope());
  const args = (s: PublicationScope): [number | string, number | string, number | string, PublicationScope['target']] => [
    s.projectId,
    s.itemId,
    s.branchId,
    s.target,
  ];

  async function load(): Promise<void> {
    const request = ++revision;
    const current = key();
    state.value = null;
    loading.value = true;
    error.value = '';
    try {
      const result = await client.load(...args(scope()));
      if (request === revision && current === key()) state.value = result;
    } catch (e) {
      if (request === revision && current === key()) error.value = firstErrorMessage(e) ?? '发布状态加载失败，请重试';
    } finally {
      if (request === revision && current === key()) loading.value = false;
    }
  }

  async function publish(): Promise<boolean> {
    if (blocked() || loading.value || publishing.value) return false;
    const currentState = state.value;
    if (!currentState || !currentState.can_publish || currentState.published) return false;
    const current = key();
    publishing.value = true;
    error.value = '';
    try {
      await client.publish(...args(scope()));
      if (current !== key()) return false;
      await load();
      return !error.value;
    } catch (e) {
      if (current === key()) error.value = firstErrorMessage(e) ?? '发布失败，请重试';
      return false;
    } finally {
      publishing.value = false;
    }
  }

  const published = computed(() => state.value?.published ?? false);
  const canPublish = computed(() => state.value?.can_publish ?? false);
  const approved = computed(() => state.value?.approved ?? false);
  const reason = computed(() => state.value?.reason ?? null);
  const publishedAt = computed(() => state.value?.published_at ?? null);

  return { state, published, canPublish, approved, reason, publishedAt, loading, publishing, error, load, publish };
}
