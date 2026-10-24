import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { probeContext } from '../shared/http/http-context';
import { AccountApplication, AccountUser, CreateTokenBody, CreatedToken, EndedResponse, MessageResponse, PersonalToken, ProfileBody, RecoveryCodes, SessionsResponse, TwoFactorSetup } from './models';

/** The signed-in account, from its own point of view. Every call needs the session cookie. */
@Injectable({ providedIn: 'root' })
export class AccountApi {
  private readonly _http = inject(HttpClient);

  /**
   * The account behind the session cookie.
   *
   * `probe` marks the call that only asks whether anybody is signed in, whose
   * 401 is an answer rather than an expired session.
   */
  me(options: { probe?: boolean } = {}): Observable<AccountUser> {
    return this._http.get<AccountUser>('/api/account', options.probe ? { context: probeContext() } : {});
  }

  updateProfile(body: ProfileBody): Observable<AccountUser> {
    return this._http.put<AccountUser>('/api/account/profile', body);
  }

  // ── Password ───────────────────────────────────────────────────────────────

  /** Every other session and remembered device ends. */
  changePassword(currentPassword: string, newPassword: string): Observable<MessageResponse> {
    return this._http.put<MessageResponse>('/api/account/password', { current_password: currentPassword, new_password: newPassword });
  }

  /**
   * A first password on an account without one; also clears a forced change.
   * The proof (there is no password to ask for): a sign-in of the last
   * fifteen minutes - `403 reauthentication_required` otherwise.
   */
  setPassword(password: string): Observable<AccountUser> {
    return this._http.post<AccountUser>('/api/account/password/set', { password });
  }

  /**
   * Password sign-in on or off. The proof: switching it off, `password` - the
   * current one, while it is a way in (`422 wrong_password`); switching it on,
   * a sign-in of the last fifteen minutes (`403 reauthentication_required`).
   */
  setPasswordEnabled(enabled: boolean, password?: string): Observable<AccountUser> {
    return this._http.put<AccountUser>('/api/account/password/enabled', password === undefined ? { enabled } : { enabled, password });
  }

  // ── Google ─────────────────────────────────────────────────────────────────

  /**
   * Google as another way in. The proof: `password`, the current one, while
   * password sign-in is on (`422 wrong_password`); else a sign-in of the last
   * fifteen minutes (`403 reauthentication_required`). The credential is
   * taken first and held for this account, so a wrong password costs no new
   * credential - the same one goes again - and nobody else can use it
   * meanwhile.
   */
  connectGoogle(credential: string, password?: string): Observable<AccountUser> {
    return this._http.post<AccountUser>('/api/account/google', password === undefined ? { credential } : { credential, password });
  }

  disconnectGoogle(): Observable<AccountUser> {
    return this._http.delete<AccountUser>('/api/account/google');
  }

  // ── Two-factor ─────────────────────────────────────────────────────────────

  setupTwoFactor(): Observable<TwoFactorSetup> {
    return this._http.post<TwoFactorSetup>('/api/account/2fa/setup', {});
  }

  /**
   * Answers the recovery codes, which are never shown again. Other sessions
   * end. The proof comes before the code: `password`, the current one, while
   * password sign-in is on (`422 wrong_password`); else a sign-in of the last
   * fifteen minutes (`403 reauthentication_required`).
   */
  enableTwoFactor(code: string, password?: string): Observable<RecoveryCodes> {
    return this._http.post<RecoveryCodes>('/api/account/2fa/enable', password === undefined ? { code } : { code, password });
  }

  disableTwoFactor(code: string): Observable<AccountUser> {
    return this._http.post<AccountUser>('/api/account/2fa/disable', { code });
  }

  regenerateRecoveryCodes(code: string): Observable<RecoveryCodes> {
    return this._http.post<RecoveryCodes>('/api/account/2fa/recovery', { code });
  }

  forgetDevices(): Observable<EndedResponse> {
    return this._http.delete<EndedResponse>('/api/account/devices');
  }

  // ── Sessions ───────────────────────────────────────────────────────────────

  sessions(): Observable<SessionsResponse> {
    return this._http.get<SessionsResponse>('/api/account/sessions');
  }

  endSession(id: number): Observable<MessageResponse> {
    return this._http.delete<MessageResponse>(`/api/account/sessions/${id}`);
  }

  /** Every session but the one making the request. */
  endOtherSessions(): Observable<EndedResponse> {
    return this._http.delete<EndedResponse>('/api/account/sessions');
  }

  // ── Applications and tokens ────────────────────────────────────────────────

  applications(): Observable<AccountApplication[]> {
    return this._http.get<{ applications: AccountApplication[] }>('/api/account/applications').pipe(map((response) => response.applications ?? []));
  }

  tokens(): Observable<PersonalToken[]> {
    return this._http.get<{ tokens: PersonalToken[] }>('/api/account/tokens').pipe(map((response) => response.tokens ?? []));
  }

  createToken(body: CreateTokenBody): Observable<CreatedToken> {
    return this._http.post<CreatedToken>('/api/account/tokens', body);
  }

  revokeToken(id: number): Observable<MessageResponse> {
    return this._http.delete<MessageResponse>(`/api/account/tokens/${id}`);
  }

  // ── Deleting the account ───────────────────────────────────────────────────

  /**
   * Asks for the account to be deleted thirty days from now; answers the
   * account with `deletion_due_at` set. It is closed at once: every other
   * session ends, and nothing is issued to any site until it is cancelled.
   *
   * The proof is the current password where the password is a way in
   * (`has_password` and `password_login_enabled`); otherwise a sign-in of the
   * last fifteen minutes, and no password is sent - an older one is refused
   * `403 reauthentication_required`.
   */
  requestDeletion(password?: string): Observable<AccountUser> {
    return this._http.post<AccountUser>('/api/account/deletion', password === undefined ? {} : { password });
  }

  /** Keeps the account: the deletion is cancelled, `deletion_due_at` back to null. What the request ended stays ended. */
  cancelDeletion(): Observable<AccountUser> {
    return this._http.delete<AccountUser>('/api/account/deletion');
  }
}
