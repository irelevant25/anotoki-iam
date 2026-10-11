import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { AutofocusDirective } from '@anotoki/lib/ui/autofocus';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { PasswordFieldComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { ContinueTo, PublicConfig } from '../../../core/api/models';
import { PublicApi } from '../../../core/api/public.api';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { PASSWORD_MAX_BYTES, dropQueryParam, isInternalPath, passwordBytes } from '../auth-helpers';

type Step = 'form' | 'done' | 'pending' | 'dead' | 'missing';

/**
 * /reset-password?token= - where the reset link lands.
 *
 * The token is held in memory and taken out of the address bar at once. A new
 * password signs the person in and ends every other session of the account -
 * a reset is how somebody takes an account back. With two-factor on, the
 * answer is `totp_required` first and a code field appears: the link proves
 * only the mailbox, and the same form goes again with the code.
 *
 * An account waiting for an administrator's approval gets its new password
 * but no session (`approval_pending`), and the page says what is left.
 */
@Component({
  selector: 'app-reset-password',
  imports: [RouterLink, TPipe, AuthCardComponent, AlertComponent, AutofocusDirective, ButtonComponent, IconComponent, PasswordFieldComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './reset-password.component.html',
  styleUrl: './reset-password.component.scss',
})
export class ResetPasswordComponent implements OnInit {
  readonly token = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _publicApi = inject(PublicApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _router = inject(Router);
  private readonly _destroyRef = inject(DestroyRef);

  protected readonly step = signal<Step>('form');
  protected readonly password = signal('');
  protected readonly passwordAgain = signal('');
  protected readonly passwordError = signal<string | null>(null);
  protected readonly againError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly continueTo = signal<ContinueTo | null>(null);
  protected readonly passwordMin = signal(8);
  protected readonly totpNeeded = signal(false);
  protected readonly totp = signal('');
  protected readonly totpError = signal<string | null>(null);

  /** The secret from the link, only ever in memory. */
  private _token = '';

  ngOnInit(): void {
    this._token = this.token() ?? '';
    dropQueryParam('token');

    if (!this._token) {
      this.step.set('missing');
      return;
    }

    this._publicApi
      .config()
      .pipe(takeUntilDestroyed(this._destroyRef))
      .subscribe({ next: (config: PublicConfig) => this.passwordMin.set(config.password_min), error: () => undefined });
  }

  protected submit(): void {
    this.formError.set(null);
    this.passwordError.set(null);
    this.againError.set(null);
    this.totpError.set(null);

    const password = this.password();
    if (password.length < this.passwordMin()) {
      this.passwordError.set(this._i18n.t('common.passwordTooShort'));
      return;
    }
    if (passwordBytes(password) > PASSWORD_MAX_BYTES) {
      this.passwordError.set(this._i18n.t('common.passwordTooLong'));
      return;
    }
    if (this.passwordAgain() !== password) {
      this.againError.set(this._i18n.t('common.passwordMismatch'));
      return;
    }

    const totp = this.totpNeeded() ? this.totp().trim() : '';
    if (this.totpNeeded() && totp === '') {
      this.totpError.set(this._i18n.t('reset.totpMissing'));
      return;
    }

    this.busy.set(true);
    this._loginApi.resetPassword(this._token, password, totp || undefined).subscribe({
      next: (response) => {
        this.busy.set(false);
        this._token = '';
        this._session.set(response.user);
        this.continueTo.set(response.continue_to);
        this.step.set('done');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        if (error.code === 'invalid_token') {
          this.step.set('dead');
        } else if (error.code === 'approval_pending') {
          // The password is set; the account waits for an administrator.
          this._token = '';
          this.step.set('pending');
        } else if (error.code === 'totp_required') {
          this.totpNeeded.set(true);
        } else if (error.code === 'totp_invalid') {
          this.totpNeeded.set(true);
          this.totp.set('');
          this.totpError.set(errorText(error, (key) => this._i18n.t(key)));
        } else if (error.code === 'invalid_password') {
          this.passwordError.set(this._i18n.t('error.invalidPassword'));
        } else {
          this.formError.set(errorText(error, (key) => this._i18n.t(key)));
        }
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
}
