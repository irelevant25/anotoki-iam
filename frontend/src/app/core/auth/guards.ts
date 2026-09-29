import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { ApiError } from '../api/api-error';
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
 * Whether somebody may use the admin console is decided by the admin layout,
 * which shows "you don't have access" in place rather than redirecting.
 */
export const sessionGuard: CanActivateFn = (_route, state) => {
  const session = inject(SessionService);
  const router = inject(Router);

  return session.ensure().pipe(
    map((user) => (user ? true : router.createUrlTree(['/login'], { queryParams: { next: state.url } }))),
    catchError((error: ApiError) =>
      of(
        error.code === 'not_set_up'
          ? router.createUrlTree(['/not-set-up'], { queryParams: { next: state.url } })
          : router.createUrlTree(['/error'], { queryParams: { code: error.code === 'network' ? 'network' : 'server_error', next: state.url } }),
      ),
    ),
  );
};
