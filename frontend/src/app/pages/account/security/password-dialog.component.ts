import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, map, of } from 'rxjs';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { PublicApi } from '../../../core/api/public.api';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { DialogComponent } from '../../../ui/dialog/dialog.component';
import { PasswordFieldComponent } from '../../../ui/password-field/password-field.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { PASSWORD_MAX_BYTES, passwordBytes } from '../../auth/auth-helpers';

/**
 * Changing the password (current, new, new again) or setting a first one on
 * an account that has none.
 *
 * A change ends every other session and remembered device, which is said
 * before it happens rather than discovered afterwards on another laptop. A
 * hidden address field lets a password manager file the new password under
 * the right account.
 */
@Component({
  selector: 'app-password-dialog',
  imports: [TPipe, AlertComponent, ButtonComponent, DialogComponent, PasswordFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './password-dialog.component.html',
  styleUrl: './password-dialog.component.scss',
})
export class PasswordDialogComponent {
  readonly mode = input.required<'change' | 'set'>();
  /** True once the password actually changed. */
  readonly closed = output<boolean>();

  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);

  protected readonly email = computed(() => this._session.user()?.email ?? '');
  protected readonly passwordMin = toSignal(
    inject(PublicApi)
      .config()
      .pipe(
        map((config) => config.password_min),
        catchError(() => of(8)),
      ),
    { initialValue: 8 },
  );

  protected readonly current = signal('');
  protected readonly next = signal('');
  protected readonly again = signal('');
  protected readonly currentError = signal<string | null>(null);
  protected readonly nextError = signal<string | null>(null);
  protected readonly againError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected submit(): void {
    this.currentError.set(null);
    this.nextError.set(null);
    this.againError.set(null);
    this.formError.set(null);

    const current = this.current();
    const next = this.next();

    if (this.mode() === 'change' && !current) {
      this.currentError.set(this._i18n.t('common.required'));
    }
    if (next.length < this.passwordMin()) {
      this.nextError.set(this._i18n.t('common.passwordTooShort'));
    } else if (passwordBytes(next) > PASSWORD_MAX_BYTES) {
      this.nextError.set(this._i18n.t('common.passwordTooLong'));
    } else if (this.mode() === 'change' && next === current) {
      this.nextError.set(this._i18n.t('security.password.unchanged'));
    } else if (this.again() !== next) {
      this.againError.set(this._i18n.t('common.passwordMismatch'));
    }
    if (this.currentError() || this.nextError() || this.againError()) {
      return;
    }

    this.busy.set(true);

    if (this.mode() === 'change') {
      this._accountApi.changePassword(current, next).subscribe({
        next: () => {
          this.busy.set(false);
          this._toast.success(this._i18n.t('security.password.changed'));
          this.closed.emit(true);
        },
        error: (error: ApiError) => this._refused(error),
      });
    } else {
      this._accountApi.setPassword(next).subscribe({
        next: (user) => {
          this.busy.set(false);
          this._session.set(user);
          this._toast.success(this._i18n.t('security.password.setDone'));
          this.closed.emit(true);
        },
        error: (error: ApiError) => this._refused(error),
      });
    }
  }

  private _refused(error: ApiError): void {
    this.busy.set(false);
    const text = errorText(error, (key) => this._i18n.t(key));
    if (error.code === 'wrong_password') {
      this.currentError.set(text);
    } else if (error.code === 'invalid_password') {
      this.nextError.set(text);
    } else {
      this.formError.set(text);
    }
  }
}
