import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, catchError, finalize, map, of, shareReplay, tap, throwError } from 'rxjs';
import { AccountApi } from '../api/account.api';
import { ApiError } from '../api/api-error';
import { AccountUser } from '../api/models';

/**
 * Who is signed in, as far as this tab knows.
 *
 * `undefined` means nobody has asked the server yet, `null` that it answered
 * "nobody". The distinction matters to the guards: an unknown session is worth
 * one request, a known empty one is an immediate redirect.
 *
 * The cookie itself is HttpOnly and invisible here, so this is only ever a
 * reflection of the last answer from `GET /api/account` or from a sign-in.
 */
@Injectable({ providedIn: 'root' })
export class SessionService {
  private readonly _accountApi = inject(AccountApi);

  private readonly _user = signal<AccountUser | null | undefined>(undefined);
  private _probe$?: Observable<AccountUser | null>;

  readonly user = this._user.asReadonly();
  readonly known = computed(() => this._user() !== undefined);
  readonly signedIn = computed(() => !!this._user());
  readonly isAdmin = computed(() => !!this._user()?.is_admin);

  /** The account, asking the server only if nobody has yet. */
  ensure(): Observable<AccountUser | null> {
    const user = this._user();
    return user !== undefined ? of(user) : this.probe();
  }

  /**
   * Asks the server whether this browser is signed in.
   *
   * A 401 is the answer "no", not a failure. Anything else - the server down,
   * a proxy with nothing behind it - is passed on, because treating it as
   * "signed out" would send a signed-in person to a sign-in form that then
   * cannot work either. Concurrent callers share one request.
   */
  probe(): Observable<AccountUser | null> {
    if (!this._probe$) {
      this._probe$ = this._accountApi.me({ probe: true }).pipe(
        map((user): AccountUser | null => user),
        catchError((error: ApiError) => (error.status === 401 ? of(null) : throwError(() => error))),
        tap((user) => this._user.set(user)),
        finalize(() => (this._probe$ = undefined)),
        shareReplay({ bufferSize: 1, refCount: true }),
      );
    }
    return this._probe$;
  }

  /** Re-reads the account after something changed it on the server. */
  refresh(): Observable<AccountUser> {
    return this._accountApi.me().pipe(tap((user) => this._user.set(user)));
  }

  set(user: AccountUser): void {
    this._user.set(user);
  }

  clear(): void {
    this._user.set(null);
  }
}
