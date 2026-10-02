// Public ChannelTask API — scoped to Project → Column → Topic → ContentItem → ProductionTask.
//
// Real endpoints (DEV-W05; every response wrapped as {"data": ...}):
//   GET    /production/channels                        -> ChannelTask[]
//   POST   /production/channels                        -> ChannelTask        (201, {channel})
//   GET    /production/channels/{channel}              -> ChannelTask
//   PATCH  /production/channels/{channel}/video        -> ChannelTask        ({video_status})
//   PATCH  /production/channels/{channel}/publish      -> ChannelTask        (publish + times)
//   POST   /production/restart-with-current-copy        -> ProductionRestartResult
//
// Contract notes enforced by the server (the UI must mirror, never bypass, these):
//   * `wechat_official` video_status is permanently `not_applicable`;
//   * `published` is terminal for the publish endpoint — no scheduled/unpublished rollback,
//     and `published_at` can never be rewritten;
//   * a `published` request must NOT carry `scheduled_at` (the server keeps the stored
//     value as scheduling history and rejects the field otherwise).
//
// No mock layer here: the workspace can never display an invented channel state.
import type {
  ApiResponse,
  Channel,
  ChannelTask,
  ProductionRestartResult,
  VideoStatus,
} from './types';
import { http } from './http';
import type { ProductionScope } from './productionTasks';

function prefix({ projectId, columnId, topicId, itemId }: ProductionScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/production`;
}

// Publish payload mirrors the server's per-target rules:
//   scheduled   → scheduled_at required, published_at prohibited
//   published   → published_at optional (server defaults to now()), scheduled_at prohibited
//   unpublished → neither time may be supplied
export type PublishUpdate =
  | { publish_status: 'scheduled'; scheduled_at: string }
  | { publish_status: 'published'; published_at?: string }
  | { publish_status: 'unpublished' };

export const channelTasksApi = {
  // 404s when the parent ProductionTask does not exist — callers must only invoke this
  // after confirming a production task is present.
  list(scope: ProductionScope): Promise<ChannelTask[]> {
    return http
      .get<ApiResponse<ChannelTask[]>>(`${prefix(scope)}/channels`)
      .then((r) => r.data.data);
  },

  // Only `channel` is accepted; artwork / production gates are decided server-side (422).
  create(scope: ProductionScope, channel: Channel): Promise<ChannelTask> {
    return http
      .post<ApiResponse<ChannelTask>>(`${prefix(scope)}/channels`, { channel })
      .then((r) => r.data.data);
  },

  get(scope: ProductionScope, channel: Channel): Promise<ChannelTask> {
    return http
      .get<ApiResponse<ChannelTask>>(`${prefix(scope)}/channels/${channel}`)
      .then((r) => r.data.data);
  },

  // Video dimension only. Never sends publish fields — the server rejects them as
  // prohibited, and the two dimensions must stay independent.
  updateVideo(
    scope: ProductionScope,
    channel: Channel,
    videoStatus: VideoStatus,
  ): Promise<ChannelTask> {
    return http
      .patch<ApiResponse<ChannelTask>>(`${prefix(scope)}/channels/${channel}/video`, {
        video_status: videoStatus,
      })
      .then((r) => r.data.data);
  },

  updatePublish(
    scope: ProductionScope,
    channel: Channel,
    payload: PublishUpdate,
  ): Promise<ChannelTask> {
    return http
      .patch<ApiResponse<ChannelTask>>(`${prefix(scope)}/channels/${channel}/publish`, payload)
      .then((r) => r.data.data);
  },

  // DEV-W05 explicit whole-chain reset. Destructive: rebinds production to the newest
  // formal revision, resets artwork to not_started and resets every non-published
  // channel task. Refused (422) when no channel exists or any channel is published.
  restartWithCurrentCopy(scope: ProductionScope): Promise<ProductionRestartResult> {
    return http
      .post<ApiResponse<ProductionRestartResult>>(`${prefix(scope)}/restart-with-current-copy`, {})
      .then((r) => r.data.data);
  },
};
