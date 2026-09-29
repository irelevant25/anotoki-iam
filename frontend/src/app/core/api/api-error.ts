import { HttpErrorResponse } from '@angular/common/http';

/**
 * Every failed request, as the pages see it.
 *
 * The interceptor turns whatever came back - the contract's
 * `{ error, code }`, an HTML error page from a PHP fatal, or no answer at all -
 * into this one shape, so a page only ever decides on `code` and never has to
 * know how the failure arrived.
 */
export interface ApiError {
  status: number;
  /** The contract's machine code, or `network` / `server_error` / `unknown` when the body had none. */
  code: string;
  /** The server's own sentence, in English. Pages prefer a translated text for codes they know. */
  message: string;
  /** Anything else the error body carried (a field name on a 409, say). */
  details: Record<string, unknown>;
}

export function isApiError(value: unknown): value is ApiError {
  return typeof value === 'object' && value !== null && 'status' in value && 'code' in value && 'message' in value && 'details' in value;
}

export function toApiError(error: unknown): ApiError {
  if (isApiError(error)) {
    return error;
  }

  if (error instanceof HttpErrorResponse) {
    // Status 0 is the browser saying there was no response at all: offline, the
    // dev proxy with no PHP behind it, or a request blocked on the way out.
    if (error.status === 0) {
      return { status: 0, code: 'network', message: 'The server could not be reached.', details: {} };
    }

    const body = typeof error.error === 'object' && error.error !== null ? (error.error as Record<string, unknown>) : null;
    const code = typeof body?.['code'] === 'string' ? (body['code'] as string) : error.status >= 500 ? 'server_error' : 'unknown';
    const message = typeof body?.['error'] === 'string' ? (body['error'] as string) : typeof body?.['message'] === 'string' ? (body['message'] as string) : error.statusText || 'The request failed.';

    const details: Record<string, unknown> = {};
    if (body) {
      for (const [key, value] of Object.entries(body)) {
        if (key !== 'code' && key !== 'error') {
          details[key] = value;
        }
      }
    }

    return { status: error.status, code, message, details };
  }

  return { status: -1, code: 'unknown', message: error instanceof Error ? error.message : 'Something went wrong.', details: {} };
}
