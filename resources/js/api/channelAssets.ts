// Public channel asset binding API — DEV-011A contract, wired in DEV-W09.
//
// Real endpoints (scoped to Project → Column → Topic → ContentItem → ProductionTask → ChannelTask):
//   GET  /production/channels/{channel}/assets           -> ChannelAssetWorkspace
//   POST /production/channels/{channel}/assets/bindings  -> ChannelAssetBinding
//
// A channel never owns artwork: a binding only points at ONE shared AssetVersion that
// already exists under the production task. Which role a channel may bind is derived
// server-side from the channel (wechat_official → copy_master, wechat_channels →
// clean_master), so the client never sends a role, an asset id, a revision or a binding
// number — the POST body is { content_page_id, asset_version_id } only.
//
// No mock layer and no fallback: the UI must never display an invented binding.
import type {
  ApiResponse,
  Channel,
  ChannelAssetBinding,
  ChannelAssetBindingInput,
  ChannelAssetWorkspace,
} from './types';
import { http } from './http';

export type ChannelAssetScope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

function prefix({ projectId, columnId, topicId, itemId }: ChannelAssetScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/production/channels`;
}

export const channelAssetsApi = {
  /**
   * Per-page binding matrix for one channel, plus the completeness flag that mirrors the
   * server-side publish gate. `available_versions` is already narrowed by the backend to
   * the pinned copy revision and this channel's expected role.
   */
  workspace(scope: ChannelAssetScope, channel: Channel): Promise<ChannelAssetWorkspace> {
    return http
      .get<ApiResponse<ChannelAssetWorkspace>>(`${prefix(scope)}/${channel}/assets`)
      .then((r) => r.data.data);
  },

  /**
   * Bind a page to one shared AssetVersion. The server derives role, asset, revision,
   * binding_no and every ownership key, and creates the binding record.
   */
  bind(
    scope: ChannelAssetScope,
    channel: Channel,
    input: ChannelAssetBindingInput,
  ): Promise<ChannelAssetBinding> {
    return http
      .post<ApiResponse<ChannelAssetBinding>>(`${prefix(scope)}/${channel}/assets/bindings`, input)
      .then((r) => r.data.data);
  },
};
