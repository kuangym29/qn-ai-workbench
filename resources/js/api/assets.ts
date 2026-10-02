// Public shared visual asset API — DEV-010B contract, wired in DEV-W08.
//
// Real endpoints (scoped to Project → Column → Topic → ContentItem → ProductionTask):
//   GET  /production/assets           -> AssetWorkspace  (the pinned page matrix)
//   GET  /production/assets/{asset}   -> AssetDetail    (slot + full version history)
//   POST /production/assets/versions  -> AssetVersion   (append a registered version)
//
// The API is only called when a ProductionTask exists: without one there is nothing to
// pin assets to and the workspace endpoint does not apply.
//
// This adapter manages *file locators only* — the UI registers where a file already lives
// (disk + path + metadata). It never uploads, downloads, opens or previews anything, and
// there is no mock layer: the UI can never show an invented asset.
import type {
  ApiResponse,
  AssetDetail,
  AssetVersion,
  AssetVersionAppendInput,
  AssetWorkspace,
} from './types';
import { http } from './http';

export type AssetScope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

function prefix({ projectId, columnId, topicId, itemId }: AssetScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/production/assets`;
}

export const assetsApi = {
  /**
   * Page matrix for the production task's pinned copy revision.
   * `pages` is a snapshot of that revision — render it as returned.
   */
  workspace(scope: AssetScope): Promise<AssetWorkspace> {
    return http
      .get<ApiResponse<AssetWorkspace>>(prefix(scope))
      .then((r) => r.data.data);
  },

  /** One slot with its complete version history (read-only). */
  detail(scope: AssetScope, assetId: number): Promise<AssetDetail> {
    return http
      .get<ApiResponse<AssetDetail>>(`${prefix(scope)}/${Number(assetId)}`)
      .then((r) => r.data.data);
  },

  /**
   * Register a new version against a slot. The first POST for a page/role pair creates
   * the Asset slot, the File record and the AssetVersion server-side, so the client never
   * creates an asset up front and never computes the next `version_no`.
   */
  appendVersion(scope: AssetScope, input: AssetVersionAppendInput): Promise<AssetVersion> {
    return http
      .post<ApiResponse<AssetVersion>>(`${prefix(scope)}/versions`, input)
      .then((r) => r.data.data);
  },
};
