import { HttpParams } from '@angular/common/http';

type QueryValue = string | number | boolean | null | undefined;

/**
 * Query parameters without the blanks.
 *
 * The admin filters send only what is set: an empty `status=` is not the same
 * request as no status at all to every backend, and it makes the URL in the
 * network tab harder to read.
 */
export function toParams(query: object | undefined): HttpParams {
  let params = new HttpParams();

  for (const [key, value] of Object.entries(query ?? {}) as [string, QueryValue][]) {
    if (value === undefined || value === null || value === '') {
      continue;
    }
    params = params.set(key, String(value));
  }

  return params;
}
