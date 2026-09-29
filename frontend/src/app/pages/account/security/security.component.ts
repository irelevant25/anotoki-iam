import { ChangeDetectionStrategy, Component, inject, linkedSignal, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { map } from 'rxjs';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { SessionService } from '../../../core/auth/session.service';
import { forgetDeviceToken } from '../../../core/auth/device-token';
import { GoogleSignInService } from '../../../core/google/google-sign-in.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { ConfirmService } from '../../../ui/confirm/confirm.service';
import { GoogleButtonComponent } from '../../../ui/google-button/google-button.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SwitchComponent } from '../../../ui/switch/switch.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { PasswordDialogComponent } from './password-dialog.component';
import { TwoFactorCodeDialogComponent } from './two-factor-code-dialog.component';
import { TwoFactorSetupDialogComponent } from './two-factor-setup-dialog.component';

type OpenDialog = 'change-password' | 'set-password' | 'setup-2fa' | 'disable-2fa' | 'regenerate-2fa' | null;

/** Few enough recovery codes that it is time to make new ones. */
const LOW_RECOVERY_CODES = 3;

/**
 * /account/security - the ways into the account, and what guards them.
 *
 * An account always keeps one way in. The backend refuses anything that
 * would leave none (`would_lock_account`); the page says so in place, next to
 * the switch or button that was refused, and puts the switch back.
 */
@Component({
  selector: 'app-security',
  imports: [
    TPipe,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    GoogleButtonComponent,
    IconComponent,
    PageHeaderComponent,
    SwitchComponent,
    PasswordDialogComponent,
    TwoFactorCodeDialogComponent,
    TwoFactorSetupDialogComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './security.component.html',
  styleUrl: './security.component.scss',
})
export class SecurityComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly user = this._session.user;
  protected readonly lowCodes = LOW_RECOVERY_CODES;
  protected readonly googleAvailable = toSignal(inject(GoogleSignInService).clientId().pipe(map(Boolean)), { initialValue: false });

  protected readonly dialog = signal<OpenDialog>(null);
  protected readonly methodsError = signal<string | null>(null);
  protected readonly googleBusy = signal(false);
  protected readonly devicesBusy = signal(false);

  /** The switch's own state: it follows the account, and is put back when a change is refused. */
  protected readonly passwordEnabled = linkedSignal(() => !!this.user()?.password_login_enabled);
  protected readonly savingPasswordEnabled = signal(false);

  protected open(dialog: OpenDialog): void {
    this.methodsError.set(null);
    this.dialog.set(dialog);
  }

  protected dialogClosed(changed: boolean): void {
    this.dialog.set(null);
    if (changed) {
      // Counts that only the server knows move with these: recovery codes,
      // remembered devices (a password change forgets them all).
      this._session.refresh().subscribe({ error: () => undefined });
    }
  }

  protected setPasswordEnabled(enabled: boolean): void {
    this.methodsError.set(null);
    this.savingPasswordEnabled.set(true);
    this._accountApi.setPasswordEnabled(enabled).subscribe({
      next: (user) => {
        this.savingPasswordEnabled.set(false);
        this._session.set(user);
        this._toast.success(this._i18n.t(enabled ? 'security.password.enabled' : 'security.password.disabled'));
      },
      error: (error: ApiError) => {
        this.savingPasswordEnabled.set(false);
        this.passwordEnabled.set(!enabled);
        this.methodsError.set(this._errorText(error));
      },
    });
  }

  protected connectGoogle(credential: string): void {
    this.methodsError.set(null);
    this.googleBusy.set(true);
    this._accountApi.connectGoogle(credential).subscribe({
      next: (user) => {
        this.googleBusy.set(false);
        this._session.set(user);
        this._toast.success(this._i18n.t('security.google.connectedToast'));
      },
      error: (error: ApiError) => {
        this.googleBusy.set(false);
        this.methodsError.set(error.code === 'invalid_token' ? this._i18n.t('error.googleFailed') : this._errorText(error));
      },
    });
  }

  protected async disconnectGoogle(): Promise<void> {
    this.methodsError.set(null);
    const sure = await this._confirm.ask({
      title: this._i18n.t('security.google.disconnectTitle'),
      message: this._i18n.t('security.google.disconnectText'),
      confirmLabel: this._i18n.t('security.google.disconnect'),
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.googleBusy.set(true);
    this._accountApi.disconnectGoogle().subscribe({
      next: (user) => {
        this.googleBusy.set(false);
        this._session.set(user);
        this._toast.success(this._i18n.t('security.google.disconnectedToast'));
      },
      error: (error: ApiError) => {
        this.googleBusy.set(false);
        this.methodsError.set(this._errorText(error));
      },
    });
  }

  protected async forgetDevices(): Promise<void> {
    const sure = await this._confirm.ask({
      title: this._i18n.t('security.devices.forgetTitle'),
      message: this._i18n.t('security.devices.forgetText'),
      confirmLabel: this._i18n.t('security.devices.forget'),
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.devicesBusy.set(true);
    this._accountApi.forgetDevices().subscribe({
      next: () => {
        this.devicesBusy.set(false);
        // This browser is one of them: its token is worthless now.
        forgetDeviceToken();
        this._toast.success(this._i18n.t('security.devices.forgotten'));
        this._session.refresh().subscribe({ error: () => undefined });
      },
      error: (error: ApiError) => {
        this.devicesBusy.set(false);
        this._toast.error(this._errorText(error));
      },
    });
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this._i18n.t(key));
  }
}
