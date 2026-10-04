import { reactive, readonly } from 'vue';
import { router } from '@inertiajs/vue3';
import type { AuthUser } from '../api/auth';
import { authApi, isCsrfOrSessionExpired, isUnauthenticated } from '../api/auth';
import { setCurrentProject } from './projectContext';

// The signed-in user, held in memory only.
//
// There is deliberately no persistence layer: no localStorage, no sessionStorage,
// no token of any kind. The Laravel session cookie *is* the credential, so a page
// reload cannot know who the user is until the server answers. That is the point --
// a client-held identity is a forgeable one, and it would also go stale the moment
// the session expires.
//
// A 401 is the normal "nobody is signed in" answer, not an error worth shouting
// about, so getCurrentUser() resolves to null in that case and only rejects on a
// genuine transport or server failure.
type AuthState = {
  user: AuthUser | null;
  loaded: boolean;
  loading: boolean;
};

const state = reactive<AuthState>({
  user: null,
  loaded: false,
  loading: false,
});
let redirectingToLogin = false;

export const authState = readonly(state) as AuthState;

export function setAuthUser(user: AuthUser | null): void {
  state.user = user;
  state.loaded = true;
}

export function clearAuthUser(): void {
  state.user = null;
  state.loaded = true;
}

/** Ask the server who we are. Resolves to null when the session is not valid. */
export async function loadCurrentUser(): Promise<AuthUser | null> {
  state.loading = true;
  try {
    const user = await authApi.getCurrentUser();
    setAuthUser(user);
    redirectingToLogin = false;
    return user;
  } catch (e) {
    if (isUnauthenticated(e) || isCsrfOrSessionExpired(e)) {
      clearAuthUser();
      return null;
    }
    throw e;
  } finally {
    state.loading = false;
  }
}

/** Drop the session server-side, then the local mirror of it. */
export async function logout(): Promise<void> {
  try {
    await authApi.logout();
  } finally {
    // Even if the request failed the local mirror must go: leaving a user on screen
    // after a failed logout is precisely the "fake signed-in" state to avoid.
    clearAuthUser();
  }
}

/** Clear both session mirrors and navigate at most once for concurrent failures. */
export async function returnToLogin(): Promise<void> {
  clearAuthUser();
  setCurrentProject(null);
  if (redirectingToLogin) return;

  redirectingToLogin = true;
  router.visit('/auth/login');
}

/**
 * Interceptor hook for axios: called when any business request fails.
 *
 * Returns true when it handled the failure by sending the user to the login page.
 * A 401 means the session is gone; a 419 means the CSRF token went stale, which a
 * reload fixes. Both must leave the app, because staying on a page whose every
 * subsequent write would fail is worse than an honest redirect.
 */
export async function handleAuthFailure(e: unknown): Promise<boolean> {
  if (!isUnauthenticated(e) && !isCsrfOrSessionExpired(e)) {
    return false;
  }
  await returnToLogin();
  return true;
}
