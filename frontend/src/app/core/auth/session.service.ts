import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, catchError, defer, finalize, map, of, shareReplay, tap, throwError } from 'rxjs';
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
  /**
   * Which signing-in an answer belongs to: it moves on with every Sign out
   * (clear()) and every sign-in (set()). A save sent before either can be
   * answered after it, with the account as saved - and that answer must not
   * sign the tab back in, nor stand in for whoever signed in since (setFrom()).
   */
  private _generation = 0;

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

  /** Re-reads the account after something changed it on the server - an answer kept as setFrom() keeps one. */
  refresh(): Observable<AccountUser> {
    return this.setFrom(this._accountApi.me());
  }

  /**
   * A sign-in's answer: the session itself (the sign-in pages). It starts a
   * new signing-in, as clear() ends one, so a save sent before it is no news.
   * Anything else that answers with the account goes through setFrom().
   */
  set(user: AccountUser): void {
    this._generation++;
    this._user.set(user);
  }

  /**
   * A request that answers with the account as it now is (`request`): when
   * the answer comes it is the session's - unless this tab signed out, or
   * signed in anew, after the request was sent. Then the answer is dropped
   * and signs nobody back in, though whoever subscribed still hears it (to
   * stop a spinner, to say it was saved); a refusal is passed on. Sent when
   * subscribed to, as any request.
   *
   * Every save that answers with the account goes through here - the account
   * pages', the theme switch's, the language chosen in the top bar: any of
   * them can be answered after Sign out. Only a sign-in's answer and the
   * probe's go to the session as they are, because they are the session.
   */
  setFrom(request: Observable<AccountUser>): Observable<AccountUser> {
    return defer(() => {
      const generation = this._generation;
      return request.pipe(
        tap((user) => {
          if (generation === this._generation) {
            this._user.set(user);
          }
        }),
      );
    });
  }

  clear(): void {
    this._generation++;
    this._user.set(null);
  }
}
