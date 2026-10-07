import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { toApiError } from '../api/api-error';
import { isSessionArea } from '../auth/next-path';
import { SessionService } from '../auth/session.service';
import { SESSION_PROBE, TRANSLATION_BUNDLE } from './http-context';

/**
 * Endpoints whose 401 is an answer, not an expired session.
 *
 * `POST /api/login/password` says 401 for a wrong password; the others either
 * never need a session or are how one begins or ends.
 */
const OWN_401_PREFIXES = ['/api/login/', '/api/register', '/api/password/', '/api/public/', '/api/logout'];

/**
 * The requests the anotoki family's migrations module makes itself
 * (`@anotoki/lib/migrations`: the status, and the Migrations page's list, file
 * and Apply). Their failures go back to it as they came, an
 * HttpErrorResponse - it reads the status (409 is "busy") and the server's own
 * `message` - once the rest has had its say: the CSRF header goes on Apply,
 * and an ended session still means the sign-in page.
 */
function fromMigrationsModule(url: string): boolean {
  const path = url.split(/[?#]/)[0];
  return path === '/api/site-status' || path === '/api/admin/migrations' || path.startsWith('/api/admin/migrations/');
}

/**
 * The four things every request to the IAM's own API needs.
 *
 * 1. `X-IAM-Request: 1` on anything that is not a GET. It is the CSRF guard: a
 *    page on another origin cannot add a custom header without a preflight,
 *    and the first-party endpoints answer none.
 *
 * 2. A 401 from an endpoint that needs a session means the session is gone -
 *    signed out elsewhere, expired, ended by an admin. The session is cleared
 *    and, inside the account and admin areas, the page is swapped for the
 *    sign-in form with a way back. On the sign-in pages themselves the page is
 *    left to show the failure: sending somebody from /login to /login would
 *    only lose the request they were in the middle of.
 *
 * 3. A 503 `not_set_up` (the anotoki family's gate: `{ code, installed,
 *    message, setup? }`) means the backend has no configuration, and no page
 *    can do anything: whatever asked, the page becomes /not-set-up, which
 *    shows the family's status page - with the installer only while the IAM
 *    was never installed. `next` is the address in the browser's bar - on the
 *    first load the router has not taken it over yet.
 *
 * 4. Every failure is reshaped into `{ status, code, message }` (ApiError), so
 *    pages decide on the contract's `code` and nothing else - but the
 *    migrations module's own requests, which get theirs as they came.
 *
 * The request for the site's words (TRANSLATION_BUNDLE) gets only the last of
 * these: whatever it fails with, the page stays where it is and reads in the
 * compiled English.
 */
export const iamInterceptor: HttpInterceptorFn = (request, next) => {
  const session = inject(SessionService);
  const router = inject(Router);

  const ownApi = request.url.startsWith('/api/') || request.url.startsWith('/.well-known/');
  const outgoing = ownApi && request.method !== 'GET' ? request.clone({ setHeaders: { 'X-IAM-Request': '1' } }) : request;

  return next(outgoing).pipe(
    catchError((error: unknown) => {
      const apiError = toApiError(error);

      if (request.context.get(TRANSLATION_BUNDLE)) {
        return throwError(() => apiError);
      }

      const sessionExpired = apiError.status === 401 && ownApi && !request.context.get(SESSION_PROBE) && !OWN_401_PREFIXES.some((prefix) => request.url.startsWith(prefix));

      if (sessionExpired) {
        session.clear();
        const current = router.url;
        if (isSessionArea(current)) {
          void router.navigate(['/login'], { queryParams: { next: current } });
        }
      }

      if (apiError.status === 503 && apiError.code === 'not_set_up' && ownApi) {
        const current = location.pathname + location.search;
        if (!current.startsWith('/not-set-up')) {
          void router.navigate(['/not-set-up'], { queryParams: { next: current }, replaceUrl: true });
        }
      }

      return throwError(() => (fromMigrationsModule(request.url) ? error : apiError));
    }),
  );
};
