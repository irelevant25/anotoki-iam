import { ChangeDetectionStrategy, Component, computed, inject, output, signal } from '@angular/core';
import { DomSanitizer, SafeUrl } from '@angular/platform-browser';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { TwoFactorSetup } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CodeInputComponent } from '../../../ui/code-input/code-input.component';
import { CopyButtonComponent } from '../../../ui/copy-button/copy-button.component';
import { DialogComponent } from '../../../ui/dialog/dialog.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { QrCodeComponent } from '../../../ui/qr-code/qr-code.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { RecoveryCodesComponent } from './recovery-codes.component';

type Step = 'loading' | 'error' | 'scan' | 'codes';

/**
 * Turning two-factor on, in the order that cannot lock anybody out:
 *
 *   1. the backend issues a secret, shown as a QR code and as text;
 *   2. a code computed from it comes back as proof it was really scanned -
 *      only then does the account start requiring codes;
 *   3. the recovery codes, shown once, and the dialog stays until the person
 *      says they have them.
 */
@Component({
  selector: 'app-two-factor-setup-dialog',
  imports: [TPipe, AlertComponent, ButtonComponent, CodeInputComponent, CopyButtonComponent, DialogComponent, IconComponent, QrCodeComponent, SpinnerComponent, RecoveryCodesComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './two-factor-setup-dialog.component.html',
  styleUrl: './two-factor-setup-dialog.component.scss',
})
export class TwoFactorSetupDialogComponent {
  /** True once two-factor is on (the recovery codes have been shown and acknowledged). */
  readonly closed = output<boolean>();

  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _sanitizer = inject(DomSanitizer);
  private readonly _toast = inject(ToastService);

  protected readonly step = signal<Step>('loading');
  protected readonly setup = signal<TwoFactorSetup | null>(null);
  protected readonly code = signal('');
  protected readonly codeError = signal<string | null>(null);
  protected readonly loadError = signal('');
  protected readonly busy = signal(false);
  protected readonly codes = signal<string[]>([]);
  protected readonly saved = signal(false);
  protected readonly username = computed(() => this._session.user()?.username ?? '');

  /** The secret in groups of four, which is how people copy a long key without losing their place. */
  protected readonly groupedSecret = computed(() => (this.setup()?.secret.match(/.{1,4}/g) ?? []).join(' '));

  /**
   * The otpauth:// link, for an authenticator app on the same device.
   *
   * Angular's URL sanitiser only lets the web's own schemes through and would
   * turn this into `unsafe:otpauth:…`. It comes from the IAM's own API and is
   * checked to be an otpauth URI here, so it is marked as trusted - nothing
   * else could become a link this way.
   */
  protected readonly appLink = computed<SafeUrl | string>(() => {
    const uri = this.setup()?.uri ?? '';
    return uri.startsWith('otpauth://') ? this._sanitizer.bypassSecurityTrustUrl(uri) : '#';
  });

  constructor() {
    this.start();
  }

  protected start(): void {
    this.step.set('loading');
    this._accountApi.setupTwoFactor().subscribe({
      next: (setup) => {
        this.setup.set(setup);
        this.step.set('scan');
      },
      error: (error: ApiError) => {
        this.loadError.set(errorText(error, (key) => this._i18n.t(key)));
        this.step.set('error');
      },
    });
  }

  /** The codes are saved: two-factor is on, and has been since the code was accepted. */
  protected finish(): void {
    this._toast.success(this._i18n.t('security.twoFactor.turnedOn'));
    this.closed.emit(true);
  }

  protected enable(): void {
    this.codeError.set(null);
    if (this.code().length !== 6) {
      this.codeError.set(this._i18n.t('common.required'));
      return;
    }

    this.busy.set(true);
    this._accountApi.enableTwoFactor(this.code()).subscribe({
      next: (response) => {
        this.busy.set(false);
        this.codes.set(response.recovery_codes);
        this.step.set('codes');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.code.set('');
        this.codeError.set(error.code === 'totp_invalid' ? this._i18n.t('error.totpInvalid') : errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }
}
