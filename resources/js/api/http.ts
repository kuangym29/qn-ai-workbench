import axios from 'axios';

// Shared HTTP client for the real DEV-003 API.
//
// - withCredentials: send the session cookie so the server-side ProjectContext (the
//   "current project") is resolved from the session, never from a request body field.
// - Accept: application/json: ensures validation failures come back as 422 JSON
//   ({"message": ..., "errors": {...}}) instead of an HTML redirect page.
// - XSRF: axios auto-reads the `XSRF-TOKEN` cookie and sends `X-XSRF-TOKEN`, satisfying
//   Laravel's VerifyCsrfToken for same-origin write requests.
export const http = axios.create({
  withCredentials: true,
  headers: {
    Accept: 'application/json',
  },
});
