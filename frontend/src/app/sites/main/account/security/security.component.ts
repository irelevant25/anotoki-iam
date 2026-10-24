import { ChangeDetectionStrategy, Component, inject, linkedSignal, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { SwitchComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { map } from 'rxjs';
import { AccountApi } from '../../../../api/account.api';
import { ApiError } from '../../../../api/api-error';
import { AccountUser } from '../../../../api/models';
import { passwordIsProof, scrollToFragment } from '../../../../shared/auth/fresh-sign-in';
import { SessionService } from '../../../../shared/auth/session.service';
import { forgetDeviceToken } from '../../common/device-token';
import { GoogleSignInService } from '../../common/google/google-sign-in.service';
import { errorText } from '../../../../shared/i18n/error-text';
import { TPipe } from '../../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../../shared/i18n/translation.service';
import { GoogleButtonComponent } from '../../common/google-button/google-button.component';
import { FreshSignInComponent } from '../fresh-sign-in.component';
import { DeletionDialogComponent } from './deletion-dialog.component';
import { PasswordDialogComponent } from './password-dialog.component';
import { ProofDialogComponent } from './proof-dialog.component';
import { SECURITY_ANCHORS } from './security-anchors';
import { TwoFactorCodeDialogComponent } from './two-factor-code-dialog.component';
import { TwoFactorSetupDialogComponent } from './two-factor-setup-dialog.component';

type OpenDialog = 'change-password' | 'set-password' | 'password-off' | 'connect-google' | 'setup-2fa' | 'disable-2fa' | 'regenerate-2fa' | 'delete-account' | null;

/** Few enough recovery codes that it is time to make new ones. */
const LOW_RECOVERY_CODES = 3;

/**
 * /account/security - the ways into the account, and what guards them.
 *
 * An account always keeps one way in. The backend refuses anything that
 * would leave none (`would_lock_account`); the page says so in place, next to
 * the switch or button that was refused, and puts the switch back.
 *
 * A session alone adds or changes no way in (docs/api.md, "The proof a
 * session alone cannot give"): switching password sign-in off and
 * connecting Google ask for the password while it is a way in
 * (ProofDialogComponent); a first password, password sign-in switched on,
 * and Google on an account without one take a sign-in of the last fifteen
 * minutes - an older one is answered with the way to sign in again
 * (FreshSignInComponent), which comes back to the part of the page it was
 * asked from.
 *
 * Last, deleting the account (DeletionDialogComponent), with the same proof.
 * While its deletion is pending, the page has nothing to say about it: the
 * notice at the top of every account page does, with the way to cancel it.
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
    FreshSignInComponent,
    DeletionDialogComponent,
    PasswordDialogComponent,
    ProofDialogComponent,
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
  protected readonly anchors = SECURITY_ANCHORS;

  protected readonly dialog = signal<OpenDialog>(null);
  protected readonly methodsError = signal<string | null>(null);
  /** What was just tried on the ways in takes a fresher sign-in than this session's: the way to one, in place. */
  protected readonly methodsFreshSignIn = signal(false);
  protected readonly googleBusy = signal(false);
  protected readonly devicesBusy = signal(false);

  /** The switch's own state: it follows the account, and is put back when a change is refused or not confirmed. */
  protected readonly passwordEnabled = linkedSignal(() => !!this.user()?.password_login_enabled);
  protected readonly savingPasswordEnabled = signal(false);

  /**
   * Google's id token, between Google handing it over and the password that
   * goes with it - in memory, for as long as that dialog is open. The server
   * holds it for this account at the first try, so after a wrong password it
   * does for another - and for nobody else.
   */
  private _googleCredential: string | null = null;

  /** What the proof dialogs send - the account each answers with is the session's, unless Sign out came first (setFrom()). */
  protected readonly switchPasswordOff = (password: string | undefined) => this._session.setFrom(this._accountApi.setPasswordEnabled(false, password));
  protected readonly connectGoogleWith = (password: string | undefined) => this._session.setFrom(this._accountApi.connectGoogle(this._googleCredential ?? '', password));
  protected readonly googleRefusal = (error: ApiError): string | null => (error.code === 'invalid_token' ? this._i18n.t('error.googleFailed') : null);

  constructor() {
    // Back from signing in again (FreshSignInComponent): to where it was asked.
    scrollToFragment();
  }

  protected open(dialog: OpenDialog): void {
    this._clearMethodsNotes();
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

  // ── Password sign-in, on and off ───────────────────────────────────────────

  protected setPasswordEnabled(enabled: boolean): void {
    this._clearMethodsNotes();
    // Off takes the password, which is the proof while it is a way in -
    // unless it cannot go off at all (no Google: the server says
    // would_lock_account before it asks for any proof).
    if (!enabled && this.user()?.google_connected && passwordIsProof(this.user())) {
      this.dialog.set('password-off');
      return;
    }

    this.savingPasswordEnabled.set(true);
    this._session.setFrom(this._accountApi.setPasswordEnabled(enabled)).subscribe({
      next: (user) => this.passwordSwitched(user),
      error: (error: ApiError) => {
        this.savingPasswordEnabled.set(false);
        if (!enabled && error.code === 'wrong_password') {
          // Google was connected elsewhere a moment ago: off it can go, with the password.
          this.dialog.set('password-off');
          return;
        }
        this.passwordEnabled.set(!enabled);
        this._methodsRefused(error);
      },
    });
  }

  /** Password sign-in switched - by the switch, or once the dialog had the password. The account it answered with is the session's by now. */
  protected passwordSwitched(user: AccountUser): void {
    this.savingPasswordEnabled.set(false);
    this.dialog.set(null);
    this._toast.success(this._i18n.t(user.password_login_enabled ? 'security.password.enabled' : 'security.password.disabled'));
  }

  /** The password was not given after all: the switch goes back to what the account says. */
  protected passwordOffDismissed(): void {
    this.dialog.set(null);
    this.passwordEnabled.set(!!this.user()?.password_login_enabled);
  }

  // ── Google ─────────────────────────────────────────────────────────────────

  protected connectGoogle(credential: string): void {
    this._clearMethodsNotes();
    // While the password is a way in, it is asked for before anything is connected.
    if (passwordIsProof(this.user())) {
      this._googleCredential = credential;
      this.dialog.set('connect-google');
      return;
    }

    this.googleBusy.set(true);
    this._session.setFrom(this._accountApi.connectGoogle(credential)).subscribe({
      next: () => this.googleConnected(),
      error: (error: ApiError) => {
        this.googleBusy.set(false);
        this._methodsRefused(error, this.googleRefusal(error));
      },
    });
  }

  /** Connected - at once, or once the dialog had the password. The account it answered with is the session's by now. */
  protected googleConnected(): void {
    this.googleBusy.set(false);
    this.googleDialogClosed();
    this._toast.success(this._i18n.t('security.google.connectedToast'));
  }

  protected googleDialogClosed(): void {
    this._googleCredential = null;
    this.dialog.set(null);
  }

  protected async disconnectGoogle(): Promise<void> {
    this._clearMethodsNotes();
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
    this._session.setFrom(this._accountApi.disconnectGoogle()).subscribe({
      next: () => {
        this.googleBusy.set(false);
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

  private _clearMethodsNotes(): void {
    this.methodsError.set(null);
    this.methodsFreshSignIn.set(false);
  }

  /** A refusal on the ways in, said in place: a sign-in too old gets the way to a fresh one, anything else its words. */
  private _methodsRefused(error: ApiError, own: string | null = null): void {
    if (own === null && error.code === 'reauthentication_required') {
      this.methodsFreshSignIn.set(true);
    } else {
      this.methodsError.set(own ?? this._errorText(error));
    }
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this._i18n.t(key));
  }
}
