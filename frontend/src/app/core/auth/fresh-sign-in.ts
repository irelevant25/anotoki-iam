import { Injectable, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, Scroll } from '@angular/router';
import { Observable, filter, map, take } from 'rxjs';
import { LoginApi } from '../api/login.api';
import { AccountUser } from '../api/models';
import { SessionService } from './session.service';

/**
 * Whether the account's own password is the proof: it is, wherever it is a
 * way in - the server's passwordLoginAvailable(). Elsewhere the proof is a
 * sign-in of the last fifteen minutes.
 */
export function passwordIsProof(user: AccountUser | null | undefined): boolean {
  return !!user?.has_password && !!user.password_login_enabled;
}

/**
 * Signing in afresh, for what a session alone may not do (docs/api.md, "The
 * proof a session alone cannot give"): without a password to ask for, the
 * proof is a sign-in of the last fifteen minutes, and an older one is refused
 * `403 reauthentication_required`.
 *
 * The IAM takes somebody's credentials afresh only once they are signed out -
 * its sign-in page passes a signed-in person straight on - so: out, then the
 * sign-in page, with the way back (`next`) to the page this is, at the part
 * of it where the thing was asked for (`#anchor`, an element's id), which
 * the page scrolls to on the way back in (scrollToFragment()).
 */
@Injectable({ providedIn: 'root' })
export class FreshSignInService {
  private readonly _loginApi = inject(LoginApi);
  private readonly _session = inject(SessionService);
  private readonly _router = inject(Router);

  /** Out, and to the sign-in page, back to this page afterwards - at `anchor`, when there is one. Fails only when signing out does. */
  signInAgain(anchor?: string): Observable<void> {
    const page = this._router.url.split('#')[0];
    const back = anchor ? `${page}#${anchor}` : page;
    return this._loginApi.logout().pipe(
      map(() => {
        this._session.clear();
        void this._router.navigate(['/login'], { queryParams: { next: back } });
      }),
    );
  }
}

/**
 * On a page opened with a fragment (`#deletion`, back from signing in again):
 * that part of it scrolled into view. Not before the router's own scroll to
 * the top of a page it opens, which comes a moment after the page is drawn
 * with its Scroll event - so once that event has gone round. Called from the
 * page's constructor.
 */
export function scrollToFragment(): void {
  const fragment = inject(ActivatedRoute).snapshot.fragment;
  if (!fragment) {
    return;
  }

  inject(Router)
    .events.pipe(
      filter((event) => event instanceof Scroll),
      take(1),
      takeUntilDestroyed(),
    )
    .subscribe(() => queueMicrotask(() => document.getElementById(fragment)?.scrollIntoView({ block: 'start' })));
}
