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
 * an hour and must not end up in a URL or in storage. With it goes the answer
 * of the register page's news box, for an account the sign-in makes.
 */
@Injectable({ providedIn: 'root' })
export class SignInHandoffService {
  private _google: GoogleHandoff | null = null;

  giveGoogleCredential(credential: string, newsMail = false): void {
    this._google = { credential, newsMail };
  }

  takeGoogleCredential(): GoogleHandoff | null {
    const google = this._google;
    this._google = null;
    return google;
  }
}

export interface GoogleHandoff {
  credential: string;
  /** The register page's news box was ticked. */
  newsMail: boolean;
}
