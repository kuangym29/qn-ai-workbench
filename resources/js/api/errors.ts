import axios from 'axios';

// Helpers for turning DEV-003 error responses into user-facing feedback.
// A 422 returns {"message": string, "errors": {field: string[]}}.
export type FieldErrors = Record<string, string[]>;

export function is404(err: unknown): boolean {
  return axios.isAxiosError(err) && err.response?.status === 404;
}

export function extractFieldErrors(err: unknown): FieldErrors {
  if (axios.isAxiosError(err) && err.response?.status === 422) {
    const data = err.response.data as { errors?: FieldErrors } | undefined;
    return data?.errors ?? {};
  }
  return {};
}

// First field-level message if any, otherwise the top-level message, otherwise a fallback.
export function firstErrorMessage(err: unknown): string | null {
  const fe = extractFieldErrors(err);
  for (const key of Object.keys(fe)) {
    if (fe[key]?.length) return fe[key][0];
  }
  if (axios.isAxiosError(err)) {
    const data = err.response?.data as { message?: string } | undefined;
    if (data?.message) return data.message;
  }
  if (err instanceof Error) return err.message;
  return null;
}
