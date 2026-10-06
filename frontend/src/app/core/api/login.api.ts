import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import {
  CodeSignInBody,
  GoogleSignInBody,
  LoginRequest,
  LogoutRequest,
  LogoutResponse,
  PasswordSignInBody,
  RedirectResponse,
  RegisterBody,
  RegisterResponse,
  RequestedResponse,
  SignInResponse,
  SignedInWithLink,
} from './models';

/**
 * Signing in, signing up, and signing out - everything that works without a session.
 *
 * A 401 from these is an answer about the credentials, never a session that
 * has expired, so the interceptor leaves all of them alone.
 */
@Injectable({ providedIn: 'root' })
export class LoginApi {
  private readonly _http = inject(HttpClient);

  // ── Authorization requests ─────────────────────────────────────────────────

  request(id: string): Observable<LoginRequest> {
    return this._http.get<LoginRequest>(`/api/login/request/${encodeURIComponent(id)}`);
  }

  /** Issues the code, once the session cookie is there. The page follows `redirect` with a full navigation. */
  accept(id: string): Observable<RedirectResponse> {
    return this._http.post<RedirectResponse>(`/api/login/request/${encodeURIComponent(id)}/accept`, {});
  }

  /** The person chose not to continue; `redirect` carries `error=access_denied` back to the application. */
  cancel(id: string): Observable<RedirectResponse> {
    return this._http.post<RedirectResponse>(`/api/login/request/${encodeURIComponent(id)}/cancel`, {});
  }

  // ── Signing in ─────────────────────────────────────────────────────────────

  password(body: PasswordSignInBody): Observable<SignInResponse> {
    return this._http.post<SignInResponse>('/api/login/password', body);
  }

  /** Always answers `requested: true`; a code is mailed only when the address has a confirmed account. */
  requestCode(email: string): Observable<RequestedResponse> {
    return this._http.post<RequestedResponse>('/api/login/code', { email });
  }

  verifyCode(body: CodeSignInBody): Observable<SignInResponse> {
    return this._http.post<SignInResponse>('/api/login/code/verify', body);
  }

  google(body: GoogleSignInBody): Observable<SignInResponse> {
    return this._http.post<SignInResponse>('/api/login/google', body);
  }

  // ── Registration and addresses ─────────────────────────────────────────────

  register(body: RegisterBody): Observable<RegisterResponse> {
    return this._http.post<RegisterResponse>('/api/register', body);
  }

  confirm(token: string): Observable<SignedInWithLink> {
    return this._http.post<SignedInWithLink>('/api/register/confirm', { token });
  }

  resendConfirmation(email: string): Observable<RequestedResponse> {
    return this._http.post<RequestedResponse>('/api/register/resend', { email });
  }

  // ── The news ───────────────────────────────────────────────────────────────

  /** The link at the end of every announcement: that account gets no more. 400 `invalid_token` for a link that is not ours. */
  stopNews(token: string): Observable<{ news_mail: false }> {
    return this._http.post<{ news_mail: false }>('/api/news/unsubscribe', { token });
  }

  // ── Passwords ──────────────────────────────────────────────────────────────

  forgotPassword(email: string, request?: string | null): Observable<RequestedResponse> {
    return this._http.post<RequestedResponse>('/api/password/forgot', request ? { email, request } : { email });
  }

  /** `totp` - an authenticator or recovery code - once the answer was `totp_required`: two-factor holds for a reset too. */
  resetPassword(token: string, password: string, totp?: string): Observable<SignedInWithLink> {
    return this._http.post<SignedInWithLink>('/api/password/reset', totp ? { token, password, totp } : { token, password });
  }

  // ── Signing out ────────────────────────────────────────────────────────────

  logoutRequest(id: string): Observable<LogoutRequest> {
    return this._http.get<LogoutRequest>(`/api/logout/request/${encodeURIComponent(id)}`);
  }

  /** Ends the current session, whether or not there was one. */
  logout(request?: string | null): Observable<LogoutResponse> {
    return this._http.post<LogoutResponse>('/api/logout', request ? { request } : {});
  }
}
