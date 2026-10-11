import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { CodeInputComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { SessionService } from '../../../core/auth/session.service';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { RecoveryCodesComponent } from './recovery-codes.component';

/**
 * The two things that need a current code: turning two-factor off, and
 * making new recovery codes.
 *
 * Turning it off accepts a recovery code too - a lost phone is exactly when
 * that is needed - so its field takes any text. New recovery codes need the
 * authenticator app itself: the backend refuses to let one recovery code mint
 * ten more of itself, which would make a leaked code permanent.
 */
@Component({
  selector: 'app-two-factor-code-dialog',
  imports: [TPipe, AlertComponent, ButtonComponent, CodeInputComponent, DialogComponent, TextFieldComponent, RecoveryCodesComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './two-factor-code-dialog.component.html',
  styleUrl: './two-factor-code-dialog.component.scss',
})
export class TwoFactorCodeDialogComponent {
  readonly mode = input.required<'disable' | 'regenerate'>();
  /** True when something changed on the account. */
  readonly closed = output<boolean>();

  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);

  protected readonly code = signal('');
  protected readonly codeError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly codes = signal<string[]>([]);
  protected readonly saved = signal(false);
  protected readonly username = computed(() => this._session.user()?.username ?? '');

  protected readonly heading = computed<TranslationKey>(() => (this.mode() === 'disable' ? 'security.twoFactor.offTitle' : 'security.twoFactor.regenerateTitle'));
  protected readonly lead = computed<TranslationKey>(() => (this.mode() === 'disable' ? 'security.twoFactor.offLead' : 'security.twoFactor.regenerateLead'));
  protected readonly action = computed<TranslationKey>(() => (this.mode() === 'disable' ? 'security.twoFactor.turnOff' : 'security.twoFactor.regenerateAction'));

  protected submit(): void {
    this.codeError.set(null);
    this.formError.set(null);
    const code = this.code().trim();

    if (!code || (this.mode() === 'regenerate' && code.length !== 6)) {
      this.codeError.set(this._i18n.t('common.required'));
      return;
    }

    this.busy.set(true);

    if (this.mode() === 'disable') {
      this._session.setFrom(this._accountApi.disableTwoFactor(code)).subscribe({
        next: () => {
          this.busy.set(false);
          this._toast.success(this._i18n.t('security.twoFactor.turnedOff'));
          this.closed.emit(true);
        },
        error: (error: ApiError) => this._refused(error),
      });
    } else {
      this._accountApi.regenerateRecoveryCodes(code).subscribe({
        next: (response) => {
          this.busy.set(false);
          this.codes.set(response.recovery_codes);
        },
        error: (error: ApiError) => this._refused(error),
      });
    }
  }

  private _refused(error: ApiError): void {
    this.busy.set(false);
    this.code.set('');
    if (error.code === 'totp_invalid') {
      this.codeError.set(this._i18n.t('error.totpInvalid'));
    } else {
      this.formError.set(errorText(error, (key) => this._i18n.t(key)));
    }
  }
}
