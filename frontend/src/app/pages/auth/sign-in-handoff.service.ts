import { Injectable } from '@angular/core';

/**
 * A Google credential passed from the register page to the login page.
 *
 * Signing up with Google is signing in with Google - the backend makes the
 * account if there is none - and everything that can follow a sign-in (the
 * two-factor step, the forced password change, "this account can't use that
 * site", continuing to the application) lives on the login page. So the
 * register page hands the credential over and navigates there, rather than
 * growing a second copy of all of it.
 *
 * In memory only, and taken once: the credential is a bearer token for about
 * an hour and must not end up in a URL or in storage.
 */
@Injectable({ providedIn: 'root' })
export class SignInHandoffService {
  private _googleCredential: string | null = null;

  giveGoogleCredential(credential: string): void {
    this._googleCredential = credential;
  }

  takeGoogleCredential(): string | null {
    const credential = this._googleCredential;
    this._googleCredential = null;
    return credential;
  }
}
