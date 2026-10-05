import { inject } from '@angular/core';
import { CanActivateFn, Router, UrlTree } from '@angular/router';
import { Observable, catchError, from, map, of, switchMap } from 'rxjs';
import { ApiError } from '../api/api-error';
import { TranslationService } from '../i18n/translation.service';
import { SessionService } from './session.service';

/**
 * The account and admin areas need somebody signed in.
 *
 * Nobody: the sign-in page, with a way back here. The session could not be
 * checked at all (the server is down): the error page, with a retry that comes
 * back here - saying "sign in" to somebody who is signed in would be wrong. The
 * IAM is not set up yet: the page that says so (the interceptor sends every
 * other request's `not_set_up` there too).
 *
 * Somebody: the page waits - two seconds at most - for the words of their
 * language. The account says which it is, and coming from the sign-in page
 * that may be another than the one on screen: without the wait, the account
 * page would be drawn in the sign-in page's language and change under the
 * reader a moment later.
 *
 * Whether somebody may use the admin panel is decided by the admin layout,
 * which shows "you don't have access" in place rather than redirecting.
 */
export const sessionGuard: CanActivateFn = (_route, state) => {
  const session = inject(SessionService);
  const router = inject(Router);
  const i18n = inject(TranslationService);

  return session.ensure().pipe(
    switchMap((user): Observable<true | UrlTree> => (user ? from(i18n.settled()).pipe(map(() => true as const)) : of(router.createUrlTree(['/login'], { queryParams: { next: state.url } })))),
    catchError((error: ApiError) =>
      of(
        error.code === 'not_set_up'
          ? router.createUrlTree(['/not-set-up'], { queryParams: { next: state.url } })
          : router.createUrlTree(['/error'], { queryParams: { code: error.code === 'network' ? 'network' : 'server_error', next: state.url } }),
      ),
    ),
  );
};
