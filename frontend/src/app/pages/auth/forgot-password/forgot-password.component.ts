import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent, AutofocusDirective, ButtonComponent, IconComponent } from '@anotoki/lib/ui';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { looksLikeEmail } from '../auth-helpers';

/**
 * /forgot-password - asks for a reset link.
 *
 * The answer is the same whether or not the address has an account, so the
 * page cannot be used to find out which addresses do. `?request=` travels with
 * the link, so the reset can offer the way back to the application.
 */
@Component({
  selector: 'app-forgot-password',
  imports: [RouterLink, TPipe, AuthCardComponent, AlertComponent, AutofocusDirective, ButtonComponent, IconComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './forgot-password.component.html',
  styleUrl: './forgot-password.component.scss',
})
export class ForgotPasswordComponent {
  readonly request = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _i18n = inject(TranslationService);

  protected readonly email = signal('');
  protected readonly emailError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly sent = signal(false);

  protected readonly requestParams = computed(() => (this.request() ? { request: this.request() } : {}));

  protected submit(): void {
    this.formError.set(null);
    const email = this.email().trim();
    if (!looksLikeEmail(email)) {
      this.emailError.set(this._i18n.t(email ? 'common.invalidEmail' : 'common.required'));
      return;
    }
    this.emailError.set(null);
    this.busy.set(true);
    this._loginApi.forgotPassword(email, this.request()).subscribe({
      next: () => {
        this.busy.set(false);
        this.sent.set(true);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }
}
