// Auth Lite API adapter — frontend half, prepared in DEV-W11-AUTH-LITE-PREP.
//
// Frozen contract (the DEV-W11 backend implements the server side later):
//   GET  /api/auth/me      -> AuthUser        | 401 when unauthenticated
//   POST /api/auth/login   -> AuthUser        | 422 with errors.email on bad credentials
//   POST /api/auth/logout  -> empty body      | 204 or an empty `data` envelope
//
// Design rules this file must keep:
// - Authentication IS the same-origin Laravel session. There is no token to store,
//   so nothing here writes to localStorage or sessionStorage, and there is no JWT
//   and no custom persistence layer. `http` already sets `withCredentials`, so the
//   session cookie rides along, and axios reads the XSRF-TOKEN cookie and sends
//   X-XSRF-TOKEN, which is what satisfies Laravel's CSRF check on the two POSTs.
// - Types live here rather than in the shared `api/types.ts` on purpose: DEV-W10 is
//   editing that file in parallel, and keeping this module self-contained means the
//   two frontend lines can be merged in either order without a conflict.
// - There is no mock user, no fake user and no development bypass. `getCurrentUser`
//   either returns the real user from the server or rejects; callers decide what an
//   unauthenticated state means. A UI must never assume it is logged in.

import type { ApiResponse } from './types';
import { http } from './http';

/** The signed-in user. Deliberately minimal: Lite V1.0 has fixed users, no roles. */
export interface AuthUser {
  id: number;
  name: string;
  email: string;
}

export interface LoginInput {
  email: string;
  password: string;
}

/**
 * Unauthenticated. Kept as a distinct return rather than a null user so callers
 * cannot accidentally treat "nobody is signed in" as a valid AuthUser.
 */
export const NO_AUTH_USER = null;
export type MaybeAuthUser = AuthUser | typeof NO_AUTH_USER;

/** True when the failure means "no valid session" (401) or "session/CSRF stale" (419). */
export function isUnauthenticated(err: unknown): boolean {
  return statusOf(err) === 401;
}

/** Laravel answers an expired or missing CSRF token with 419. */
export function isCsrfOrSessionExpired(err: unknown): boolean {
  return statusOf(err) === 419;
}

function statusOf(err: unknown): number | null {
  if (typeof err !== 'object' || err === null) return null;
  const response = (err as { response?: { status?: number } }).response;
  return typeof response?.status === 'number' ? response.status : null;
}

export const authApi = {
  /**
   * The current user, or a 401 rejection. Never a fabricated identity.
   */
  getCurrentUser(): Promise<AuthUser> {
    return http.get<ApiResponse<AuthUser>>('/api/auth/me').then((r) => r.data.data);
  },

  /**
   * Exchange credentials for a session. The server sets the session cookie; nothing
   * is returned to store. Bad credentials surface as 422 with `errors.email`, so the
   * form can attach the message to the email field.
   *
   * The password is sent once and is never logged, echoed or persisted.
   */
  login(input: LoginInput): Promise<AuthUser> {
    return http.post<ApiResponse<AuthUser>>('/api/auth/login', input).then((r) => r.data.data);
  },

  /**
   * Drop the session. Accepts either a 204 with no body or an empty `data` envelope,
   * so the success check tolerates both shapes instead of assuming one.
   */
  logout(): Promise<void> {
    return http.post('/api/auth/logout').then(() => undefined);
  },
};
