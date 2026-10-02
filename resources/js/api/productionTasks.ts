// Public ProductionTask API — scoped to Project → Column → Topic → ContentItem.
//
// Real endpoints (DEV-009A; every response wrapped as {"data": ...}):
//   GET    /production                    -> ProductionTask | null   (null when not created)
//   POST   /production                    -> ProductionTask          (201, empty body)
//   PATCH  /production                    -> ProductionTask          (artwork_status only)
//   POST   /production/use-current-copy    -> ProductionTask          (empty body)
//
// The real API is the only source of truth here: there is deliberately NO mock layer, so
// the workspace can never display an invented production state. Every response is
// unwrapped from the {"data": ...} envelope in one place.
import type { ApiResponse, ArtworkStatus, ProductionTask } from './types';
import { http } from './http';

export type ProductionScope = {
  projectId: number | string;
  columnId: number | string;
  topicId: number | string;
  itemId: number | string;
};

// Numbers are normalized so string props coming from Inertia still produce well-formed URLs.
function prefix({ projectId, columnId, topicId, itemId }: ProductionScope): string {
  return `/api/projects/${Number(projectId)}/columns/${Number(columnId)}/topics/${Number(topicId)}/items/${Number(itemId)}/production`;
}

export const productionTasksApi = {
  // Returns null when the item has no production task yet ({"data": null}).
  get(scope: ProductionScope): Promise<ProductionTask | null> {
    return http
      .get<ApiResponse<ProductionTask | null>>(prefix(scope))
      .then((r) => r.data.data);
  },

  // Empty body on purpose: the server pins the current formal revision itself and
  // refuses to accept a client-supplied revision id.
  create(scope: ProductionScope): Promise<ProductionTask> {
    return http
      .post<ApiResponse<ProductionTask>>(prefix(scope), {})
      .then((r) => r.data.data);
  },

  // PATCH only ever sends `artwork_status`; ownership keys and the revision binding are
  // server-managed and rejected as prohibited.
  updateArtwork(
    scope: ProductionScope,
    artworkStatus: ArtworkStatus,
  ): Promise<ProductionTask> {
    return http
      .patch<ApiResponse<ProductionTask>>(prefix(scope), {
        artwork_status: artworkStatus,
      })
      .then((r) => r.data.data);
  },

  // DEV-009A: only allowed while NO channel task exists. Resets artwork_status to
  // not_started and rebinds the task to the newest formal revision.
  useCurrentCopy(scope: ProductionScope): Promise<ProductionTask> {
    return http
      .post<ApiResponse<ProductionTask>>(`${prefix(scope)}/use-current-copy`, {})
      .then((r) => r.data.data);
  },
};
