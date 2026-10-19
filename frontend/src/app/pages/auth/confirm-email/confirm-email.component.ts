import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { AutofocusDirective } from '@anotoki/lib/ui/autofocus';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { ContinueTo } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { dropQueryParam, isInternalPath, looksLikeEmail } from '../auth-helpers';

type Step = 'ready' | 'working' | 'totp' | 'done' | 'pending' | 'confirmed' | 'other-account' | 'failed' | 'missing';

/**
 * /confirm-email?token= - where the confirmation link lands.
 *
 * The token is taken out of the address bar before anything else, so it does
 * not stay in the history or travel on with a copied URL - and it is sent only
 * when the person presses "Confirm my address" (the security review of
 * 2026-10-19). A link that confirmed itself as the page opened let anybody
 * who got somebody to open one - an attacker's own new account's link -
 * confirm it and sign that browser in to it, whatever it was signed in to.
 *
 * The press confirms the address and signs the person in (holding the mailbox
 * is the proof), and the page offers the way back to the application the
 * registration started from, if any - unless the server says otherwise, and
 * it is the rule:
 *   - `other_account`: this browser was signed in to another account, which
 *     stays signed in - the page offers to sign out and sign in with the
 *     account just confirmed, or to stay;
 *   - `already_confirmed` or `sign_in`: the address is confirmed and the link
 *     signed nobody in - an older link, or one used already -: the sign-in
 *     page;
 *   - `totp_required`: the account has two-factor on, so the link alone does
 *     not sign in to it, as a reset's does not - a code field, and the same
 *     press again with the code.
 *
 * An account waiting for an administrator's approval has its address
 * confirmed all the same, but is not signed in (`approval_pending`): the page
 * says the address is done and what is left - and that a mail will come.
 */
@Component({
  selector: 'app-confirm-email',
  imports: [RouterLink, TPipe, AuthCardComponent, AlertComponent, AutofocusDirective, ButtonComponent, IconComponent, SpinnerComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './confirm-email.component.html',
  styleUrl: './confirm-email.component.scss',
})
export class ConfirmEmailComponent implements OnInit {
  /** `?token=` from the mailed link. Held here, never left in the address bar. */
  readonly token = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _router = inject(Router);

  protected readonly step = signal<Step>('ready');
  protected readonly continueTo = signal<ContinueTo | null>(null);
  protected readonly failure = signal('');
  protected readonly formError = signal<string | null>(null);
  protected readonly totp = signal('');
  protected readonly totpError = signal<string | null>(null);
  protected readonly email = signal('');
  protected readonly emailError = signal<string | null>(null);
  protected readonly info = signal<string | null>(null);
  protected readonly busy = signal(false);

  /** The secret from the link, only ever in memory - and sent only by the press. */
  private _token = '';

  ngOnInit(): void {
    this._token = this.token() ?? '';
    dropQueryParam('token');
    this.step.set(this._token ? 'ready' : 'missing');
  }

  /** The press: the link sent - with the code, once one was asked for. */
  protected confirm(): void {
    if (this.busy() || !this._token) {
      return;
    }

    const withCode = this.step() === 'totp';
    const totp = withCode ? this.totp().trim() : '';
    this.formError.set(null);
    this.totpError.set(null);
    if (withCode && !totp) {
      this.totpError.set(this._i18n.t('reset.totpMissing'));
      return;
    }

    this.busy.set(true);
    if (!withCode) {
      this.step.set('working');
    }
    this._loginApi.confirm(this._token, totp || undefined).subscribe({
      next: (response) => {
        this.busy.set(false);
        this._token = '';
        if (response.signed_in) {
          this._session.set(response.user);
          this.continueTo.set(response.continue_to);
          this.step.set('done');
        } else {
          // Nobody signed in by the link: this browser stays as it was.
          this.step.set(response.reason === 'other_account' ? 'other-account' : 'confirmed');
        }
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this._refused(error, withCode);
      },
    });
  }

  /** Out of the other account, and to the sign-in page - for the account just confirmed. */
  protected signOutAndIn(): void {
    this.formError.set(null);
    this.busy.set(true);
    this._loginApi.logout().subscribe({
      next: () => {
        this.busy.set(false);
        this._session.clear();
        void this._router.navigateByUrl('/login');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  protected go(url: string): void {
    if (isInternalPath(url)) {
      void this._router.navigateByUrl(url);
    } else {
      window.location.assign(url);
    }
  }

  protected resend(): void {
    this.info.set(null);
    const email = this.email().trim();
    if (!looksLikeEmail(email)) {
      this.emailError.set(this._i18n.t(email ? 'common.invalidEmail' : 'common.required'));
      return;
    }
    this.emailError.set(null);
    this.busy.set(true);
    this._loginApi.resendConfirmation(email).subscribe({
      next: () => {
        this.busy.set(false);
        this.info.set(this._i18n.t('confirm.resent'));
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.emailError.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  /** Every refusal, turned into the step that helps; the link kept while it may still be pressed again. */
  private _refused(error: ApiError, withCode: boolean): void {
    switch (error.code) {
      case 'approval_pending':
        this._token = '';
        this.step.set('pending');
        return;
      case 'invalid_token':
        this._token = '';
        this.failure.set(this._i18n.t('confirm.failedText'));
        this.step.set('failed');
        return;
      case 'totp_required':
        this.totp.set('');
        this.step.set('totp');
        return;
      case 'totp_invalid':
      case 'authenticator_paused':
        // A wrong code, or the account's codes paused after five wrong ones
        // (fifteen minutes): said under the field, where a recovery code
        // still works.
        this.totp.set('');
        this.step.set('totp');
        this.totpError.set(errorText(error, (key) => this._i18n.t(key)));
        return;
    }

    // Anything else - the network, the limiter, signing in switched off -
    // said where the press was, which may be made again.
    const text = errorText(error, (key) => this._i18n.t(key));
    if (withCode) {
      this.totpError.set(text);
    } else {
      this.step.set('ready');
      this.formError.set(text);
    }
  }
}
