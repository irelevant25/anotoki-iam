import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { Observable, catchError, forkJoin, of } from 'rxjs';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { LoginRequest, PublicConfig } from '../../../core/api/models';
import { PublicApi } from '../../../core/api/public.api';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { GoogleButtonComponent } from '../../../ui/google-button/google-button.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PasswordFieldComponent } from '../../../ui/password-field/password-field.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { PASSWORD_MAX_BYTES, looksLikeEmail, passwordBytes, splitAround } from '../auth-helpers';
import { SignInHandoffService } from '../sign-in-handoff.service';

type Step = 'loading' | 'load-error' | 'form' | 'sent' | 'not-sent';

interface FieldErrors {
  username?: string;
  email?: string;
  password?: string;
  again?: string;
}

/**
 * /register - a new account, confirmed by a mailed link before it works.
 *
 * `?request=` is passed on to the backend, which remembers it in the link so
 * the confirmation can offer the way back to the application. Signing up with
 * Google is a sign-in (the backend makes the account), so it is handed to the
 * login page, which knows every way that can continue.
 */
@Component({
  selector: 'app-register',
  imports: [
    RouterLink,
    TPipe,
    AuthCardComponent,
    AlertComponent,
    AutofocusDirective,
    ButtonComponent,
    GoogleButtonComponent,
    IconComponent,
    PasswordFieldComponent,
    SpinnerComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './register.component.html',
  styleUrl: './register.component.scss',
})
export class RegisterComponent implements OnInit {
  readonly request = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _publicApi = inject(PublicApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _router = inject(Router);
  private readonly _handoff = inject(SignInHandoffService);
  private readonly _destroyRef = inject(DestroyRef);

  protected readonly step = signal<Step>('loading');
  protected readonly config = signal<PublicConfig | null>(null);
  protected readonly authRequest = signal<LoginRequest | null>(null);
  protected readonly application = computed(() => this.authRequest()?.application ?? null);

  protected readonly username = signal('');
  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly passwordAgain = signal('');

  protected readonly busy = signal(false);
  protected readonly errors = signal<FieldErrors>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly sentTo = signal('');
  protected readonly info = signal<string | null>(null);

  protected readonly forAppParts = computed(() => splitAround(this._i18n.t('register.forApp'), '{app}'));
  protected readonly requestParams = computed(() => (this.request() ? { request: this.request() } : {}));
  protected readonly passwordMin = computed(() => this.config()?.password_min ?? 8);

  ngOnInit(): void {
    this.load();
  }

  protected load(): void {
    this.step.set('loading');
    const id = this.request();
    // An expired request only loses the application's name here; the account
    // can still be made, so it is not an error worth stopping for.
    const request$: Observable<LoginRequest | null> = id ? this._loginApi.request(id).pipe(catchError(() => of(null))) : of(null);

    forkJoin({ config: this._publicApi.config(), request: request$ })
      .pipe(takeUntilDestroyed(this._destroyRef))
      .subscribe({
        next: ({ config, request }) => {
          this.config.set(config);
          this.authRequest.set(request);
          if (request?.login_hint && !this.email()) {
            this.email.set(request.login_hint);
          }
          this.step.set('form');
        },
        error: (error: ApiError) => {
          this.loadError.set(this._errorText(error));
          this.step.set('load-error');
        },
      });
  }

  protected submit(): void {
    this.formError.set(null);
    const username = this.username().trim();
    const email = this.email().trim();
    const password = this.password();
    const errors: FieldErrors = {};

    if (!username) {
      errors.username = this.t('common.required');
    } else if (username.length > 100) {
      errors.username = this.t('error.invalidUsername');
    }
    if (!email) {
      errors.email = this.t('common.required');
    } else if (!looksLikeEmail(email)) {
      errors.email = this.t('common.invalidEmail');
    }
    if (password.length < this.passwordMin()) {
      errors.password = this.t('common.passwordTooShort');
    } else if (passwordBytes(password) > PASSWORD_MAX_BYTES) {
      errors.password = this.t('common.passwordTooLong');
    }
    if (!errors.password && this.passwordAgain() !== password) {
      errors.again = this.t('common.passwordMismatch');
    }

    this.errors.set(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    this.busy.set(true);
    this._loginApi.register({ username, email, password, language: this._i18n.language(), ...(this.request() ? { request: this.request() } : {}) }).subscribe({
      next: (response) => {
        this.busy.set(false);
        this.sentTo.set(response.email);
        this.password.set('');
        this.passwordAgain.set('');
        this.step.set(response.sent ? 'sent' : 'not-sent');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this._refused(error);
      },
    });
  }

  protected resend(): void {
    this.busy.set(true);
    this.info.set(null);
    this.formError.set(null);
    this._loginApi.resendConfirmation(this.sentTo()).subscribe({
      next: () => {
        this.busy.set(false);
        this.info.set(this.t('register.resent'));
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(this._errorText(error));
      },
    });
  }

  protected signUpWithGoogle(credential: string): void {
    this._handoff.giveGoogleCredential(credential);
    void this._router.navigate(['/login'], { queryParams: this.requestParams() });
  }

  protected t(key: TranslationKey, params?: Record<string, string | number | null | undefined>): string {
    return this._i18n.t(key, params);
  }

  private _refused(error: ApiError): void {
    switch (error.code) {
      case 'username_taken':
      case 'invalid_username':
        this.errors.set({ username: this._errorText(error) });
        return;
      case 'email_taken':
      case 'invalid_email':
        this.errors.set({ email: this._errorText(error) });
        return;
      case 'invalid_password':
        this.errors.set({ password: this._errorText(error) });
        return;
    }
    this.formError.set(this._errorText(error));
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this.t(key));
  }
}
