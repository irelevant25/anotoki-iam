import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { ISSUED_AS } from '@anotoki/lib/auth/marker';
import { ANOTOKI_ERROR_REPORT } from '@anotoki/lib/error-report';
import { ANOTOKI_TRANSLATION_BUNDLE } from '@anotoki/lib/translations';
import { catchError, throwError } from 'rxjs';
import { toApiError } from '../../api/api-error';
import { isSessionArea } from '../auth/next-path';
import { SessionService } from '../auth/session.service';
import { SESSION_PROBE } from './http-context';

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
 * The family's site settings' read (`GET /api/site-settings`, made by
 * `@anotoki/lib/site-settings` as the site starts, and again after the admin
 * panel's Site settings page saves): what every page is told - the
 * announcement, for the IAM. Its failure leaves no bar and is never a reason
 * to move a page, as the words' own request's is not.
 */
function siteSettingsRead(method: string, url: string): boolean {
  return method === 'GET' && url.split(/[?#]/)[0] === '/api/site-settings';
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
 * The request for the site's words (`GET /api/translations/{code}`, which the
 * family's translations mark ANOTOKI_TRANSLATION_BUNDLE) gets only the last of
 * these: whatever it fails with, the page stays where it is and reads in the
 * compiled English. Its failure is never a reason to move the page - the
 * request is made while the site starts, before any page exists to be sent
 * away from - so no `/not-set-up` and no session cleared: a page's own first
 * request says `not_set_up` a moment later, and that one does the sending.
 * So does the site settings' read (`GET /api/site-settings`, the
 * announcement's), made as the site starts too: its failure is no bar, never
 * a page moved.
 *
 * The report of a page's own error (`POST /api/client-errors`, which the
 * family's reporter marks ANOTOKI_ERROR_REPORT - `provideAnotokiErrorReport()`
 * in app.config.ts) gets only the first: the CSRF header, which is what lets
 * it in, as on every write - never an exemption. Whatever it fails with is
 * the console's alone: no page moves and no session is cleared for it, and it
 * goes back as it came, an HttpErrorResponse - the reporter reads a 429 to
 * stop reporting for the page load.
 *
 * So does the feedback form's message (`POST /api/feedback`, which the
 * family's form marks as anybody's - ISSUED_AS 'anybody', @anotoki/lib/auth/marker:
 * a request the server takes from anybody, whose failure is its maker's to
 * say): the CSRF header, and its failure back as it came - the form reads the
 * HttpErrorResponse's status, code, field and retry_after, and says each in
 * the reader's words; no page is moved and no session cleared for it.
 */
export const iamInterceptor: HttpInterceptorFn = (request, next) => {
  const session = inject(SessionService);
  const router = inject(Router);

  const ownApi = request.url.startsWith('/api/') || request.url.startsWith('/.well-known/');
  const outgoing = ownApi && request.method !== 'GET' ? request.clone({ setHeaders: { 'X-IAM-Request': '1' } }) : request;

  return next(outgoing).pipe(
    catchError((error: unknown) => {
      if (request.context.get(ANOTOKI_ERROR_REPORT) || request.context.get(ISSUED_AS) === 'anybody') {
        return throwError(() => error);
      }

      const apiError = toApiError(error);

      if (request.context.get(ANOTOKI_TRANSLATION_BUNDLE) || siteSettingsRead(request.method, request.url)) {
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
