import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { Observable, catchError, forkJoin, of, switchMap, throwError } from 'rxjs';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { AccountUser, LoginRequest, PublicConfig, SignInOptions, SignInResponse } from '../../../core/api/models';
import { PublicApi } from '../../../core/api/public.api';
import { readDeviceToken, storeDeviceToken } from '../../../core/auth/device-token';
import { safeNext } from '../../../core/auth/next-path';
import { SessionService } from '../../../core/auth/session.service';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CheckboxComponent } from '../../../ui/checkbox/checkbox.component';
import { CodeInputComponent } from '../../../ui/code-input/code-input.component';
import { GoogleButtonComponent } from '../../../ui/google-button/google-button.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PasswordFieldComponent } from '../../../ui/password-field/password-field.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { PASSWORD_MAX_BYTES, looksLikeEmail, passwordBytes, splitAround } from '../auth-helpers';
import { SignInHandoffService } from '../sign-in-handoff.service';

/**
 * The steps of signing in. Every refusal the backend can give maps to one of
 * them, so the person always sees what to do next rather than a bare "no".
 */
type Step = 'loading' | 'load-error' | 'expired' | 'form' | 'code' | 'totp' | 'unconfirmed' | 'other-way' | 'change-password' | 'denied' | 'app-disabled' | 'continuing';

/** The sign-in being attempted, kept so the two-factor step can send the whole of it again with the code. */
type PendingSignIn = { kind: 'password'; email: string; password: string } | { kind: 'code'; email: string; code: string } | { kind: 'google'; credential: string };

/** How long before another sign-in code may be asked for. */
const RESEND_COOLDOWN_MS = 30_000;

/**
 * /login - signing in, and finishing an application's authorization request.
 *
 * Opened by an application (`?request=`), a successful sign-in ends in
 * `POST /api/login/request/{id}/accept` and a full navigation back to it.
 * Opened directly (`?next=`), it ends on a page of this site.
 *
 * Somebody who is already signed in never sees the form: with a request it is
 * accepted at once, without one they go straight on. The form comes back only
 * when the application asked for fresh credentials (`login_required`).
 */
@Component({
  selector: 'app-login',
  imports: [
    RouterLink,
    TPipe,
    AuthCardComponent,
    AlertComponent,
    AutofocusDirective,
    ButtonComponent,
    CheckboxComponent,
    CodeInputComponent,
    GoogleButtonComponent,
    IconComponent,
    PasswordFieldComponent,
    SpinnerComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent implements OnInit {
  /** `?request=` - a pending authorization request from an application. */
  readonly request = input<string | undefined>();
  /** `?next=` - a path on this site to go to afterwards, when there is no request. */
  readonly next = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _accountApi = inject(AccountApi);
  private readonly _publicApi = inject(PublicApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _handoff = inject(SignInHandoffService);
  private readonly _destroyRef = inject(DestroyRef);

  protected readonly step = signal<Step>('loading');
  protected readonly config = signal<PublicConfig | null>(null);
  protected readonly authRequest = signal<LoginRequest | null>(null);
  protected readonly application = computed(() => this.authRequest()?.application ?? null);
  protected readonly user = this._session.user;

  // ── The form ───────────────────────────────────────────────────────────────
  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly remember = signal(true);
  protected readonly code = signal('');
  protected readonly totp = signal('');
  protected readonly recoveryMode = signal(false);
  protected readonly rememberDevice = signal(false);
  protected readonly unconfirmedEmail = signal('');

  // ── A forced password change ───────────────────────────────────────────────
  protected readonly currentPassword = signal('');
  protected readonly newPassword = signal('');
  protected readonly newPasswordAgain = signal('');
  protected readonly askCurrent = signal(false);
  protected readonly changeErrors = signal<{ current?: string; next?: string; again?: string }>({});

  // ── What the page is telling the person ────────────────────────────────────
  protected readonly busy = signal(false);
  protected readonly formError = signal<string | null>(null);
  protected readonly emailError = signal<string | null>(null);
  protected readonly passwordError = signal<string | null>(null);
  protected readonly codeError = signal<string | null>(null);
  protected readonly totpError = signal<string | null>(null);
  protected readonly notice = signal<string | null>(null);
  protected readonly info = signal<string | null>(null);
  protected readonly loadError = signal<string | null>(null);

  private readonly _now = signal(Date.now());
  private readonly _resendAt = signal(0);
  protected readonly resendIn = computed(() => Math.max(0, Math.ceil((this._resendAt() - this._now()) / 1000)));

  /** "Sign in to continue to" / "{app}" / "" - the name goes in bold between the halves. */
  protected readonly continueParts = computed(() => splitAround(this._i18n.t('login.continueTo'), '{app}'));

  /** Where the links to the other sign-in pages carry the request, so it survives the detour. */
  protected readonly requestParams = computed(() => (this.request() ? { request: this.request() } : {}));

  protected readonly showCurrentPassword = computed(() => !!this.user()?.has_password && (this.askCurrent() || this._knownPassword() === null));

  private _pending: PendingSignIn | null = null;
  private _retry: (() => void) | null = null;
  private _ticker: ReturnType<typeof setInterval> | null = null;

  constructor() {
    this._destroyRef.onDestroy(() => this._stopTicker());
  }

  ngOnInit(): void {
    this.load();
  }

  // ── Loading ────────────────────────────────────────────────────────────────

  protected load(): void {
    this.step.set('loading');

    const id = this.request();
    const request$: Observable<LoginRequest | 'expired' | null> = id
      ? this._loginApi.request(id).pipe(catchError((error: ApiError) => (error.status === 404 ? of('expired' as const) : throwError(() => error))))
      : of(null);

    forkJoin({ config: this._publicApi.config(), request: request$, user: this._session.probe() })
      .pipe(takeUntilDestroyed(this._destroyRef))
      .subscribe({
        next: ({ config, request, user }) => {
          this.config.set(config);

          if (request === 'expired') {
            this.goTo('expired');
            return;
          }

          this.authRequest.set(request);
          if (request?.login_hint && !this.email()) {
            this.email.set(request.login_hint);
          }

          const credential = this._handoff.takeGoogleCredential();
          if (credential) {
            this.signInWithGoogle(credential);
          } else if (user) {
            this._continueAfterSignIn(user);
          } else {
            this.goTo('form');
          }
        },
        error: (error: ApiError) => this._failed(error, () => this.load()),
      });
  }

  protected retry(): void {
    (this._retry ?? (() => this.load()))();
  }

  // ── Email and password ─────────────────────────────────────────────────────

  protected submitPassword(): void {
    this._clearErrors();
    const email = this.email().trim();
    const password = this.password();

    if (!email) {
      this.emailError.set(this.t('common.required'));
    } else if (!looksLikeEmail(email)) {
      this.emailError.set(this.t('common.invalidEmail'));
    }
    if (!password) {
      this.passwordError.set(this.t('common.required'));
    }
    if (this.emailError() || this.passwordError()) {
      return;
    }

    this._pending = { kind: 'password', email, password };
    this._signIn();
  }

  // ── An emailed code ────────────────────────────────────────────────────────

  protected sendCode(): void {
    this._clearErrors();
    const email = this.email().trim();

    if (!email) {
      this.emailError.set(this.t('login.code.emailFirst'));
      return;
    }
    if (!looksLikeEmail(email)) {
      this.emailError.set(this.t('common.invalidEmail'));
      return;
    }

    this.busy.set(true);
    this._loginApi.requestCode(email).subscribe({
      next: () => {
        this.busy.set(false);
        this.code.set('');
        this.goTo('code');
        this._startCooldown();
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(this._errorText(error));
      },
    });
  }

  protected resendCode(): void {
    if (this.resendIn() > 0) {
      return;
    }
    this.codeError.set(null);
    this.busy.set(true);
    this._loginApi.requestCode(this.email().trim()).subscribe({
      next: () => {
        this.busy.set(false);
        this.info.set(this.t('login.code.resent'));
        this._startCooldown();
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.codeError.set(this._errorText(error));
      },
    });
  }

  protected submitCode(): void {
    if (this.busy()) {
      return;
    }
    this.codeError.set(null);
    const code = this.code();
    if (code.length !== 6) {
      this.codeError.set(this.t('error.invalidCode'));
      return;
    }
    this._pending = { kind: 'code', email: this.email().trim(), code };
    this._signIn();
  }

  // ── Google ─────────────────────────────────────────────────────────────────

  protected signInWithGoogle(credential: string): void {
    this._clearErrors();
    this._pending = { kind: 'google', credential };
    this._signIn();
  }

  // ── The second factor ──────────────────────────────────────────────────────

  protected submitTotp(): void {
    this.totpError.set(null);
    const value = this.totp().trim();
    if (!value || (!this.recoveryMode() && value.length !== 6)) {
      this.totpError.set(this.t('common.required'));
      return;
    }
    this._signIn(value);
  }

  protected toggleRecovery(): void {
    this.recoveryMode.set(!this.recoveryMode());
    this.totp.set('');
    this.totpError.set(null);
  }

  // ── An unconfirmed address ─────────────────────────────────────────────────

  protected resendConfirmation(): void {
    const email = this.unconfirmedEmail();
    if (!email) {
      return;
    }
    this.busy.set(true);
    this.formError.set(null);
    this._loginApi.resendConfirmation(email).subscribe({
      next: () => {
        this.busy.set(false);
        this.info.set(this.t('login.unconfirmed.resent'));
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(this._errorText(error));
      },
    });
  }

  // ── A password somebody else chose ─────────────────────────────────────────

  protected submitPasswordChange(): void {
    const user = this.user();
    if (!user) {
      this.goTo('form');
      return;
    }

    const next = this.newPassword();
    const again = this.newPasswordAgain();
    const current = this.showCurrentPassword() ? this.currentPassword() : (this._knownPassword() ?? '');
    const min = this.config()?.password_min ?? 8;
    const errors: { current?: string; next?: string; again?: string } = {};

    if (user.has_password && !current) {
      errors.current = this.t('common.required');
    }
    if (next.length < min) {
      errors.next = this.t('common.passwordTooShort');
    } else if (passwordBytes(next) > PASSWORD_MAX_BYTES) {
      errors.next = this.t('common.passwordTooLong');
    } else if (user.has_password && next === current) {
      errors.next = this.t('login.change.unchanged');
    }
    if (!errors.next && again !== next) {
      errors.again = this.t('common.passwordMismatch');
    }

    this.changeErrors.set(errors);
    if (errors.current || errors.next || errors.again) {
      return;
    }

    this.busy.set(true);
    this.formError.set(null);

    const change$: Observable<AccountUser> = user.has_password ? this._accountApi.changePassword(current, next).pipe(switchMap(() => this._session.refresh())) : this._accountApi.setPassword(next);

    change$.subscribe({
      next: (fresh) => {
        this.busy.set(false);
        this._session.set(fresh);
        this._pending = null;
        this.currentPassword.set('');
        this.newPassword.set('');
        this.newPasswordAgain.set('');
        if (this.request()) {
          this._accept();
        } else {
          void this._router.navigateByUrl(safeNext(this.next()));
        }
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        if (error.code === 'wrong_password') {
          this.askCurrent.set(true);
          this.changeErrors.set({ current: this.t('error.wrongPassword') });
        } else if (error.code === 'invalid_password') {
          this.changeErrors.set({ next: this.t('error.invalidPassword') });
        } else if (error.status === 401) {
          this._session.clear();
          this.startOver();
        } else {
          this.formError.set(this._errorText(error));
        }
      },
    });
  }

  // ── Ways out ───────────────────────────────────────────────────────────────

  protected startOver(): void {
    this._pending = null;
    this.password.set('');
    this.code.set('');
    this.totp.set('');
    this.recoveryMode.set(false);
    this.goTo('form');
  }

  /** Signs out of the account that may not use the application, and offers the form again. */
  protected useAnotherAccount(): void {
    this.busy.set(true);
    this._loginApi.logout().subscribe({
      next: () => {
        this.busy.set(false);
        this._session.clear();
        this.email.set('');
        this.startOver();
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.formError.set(this._errorText(error));
      },
    });
  }

  /** "Back to {app}": tells the application the person chose not to continue. */
  protected cancel(): void {
    const id = this.request();
    if (!id) {
      return;
    }
    this.busy.set(true);
    this._loginApi.cancel(id).subscribe({
      next: ({ redirect }) => window.location.assign(redirect),
      error: () => {
        // The request is already gone; the application's front door is the
        // next best place to go back to.
        const home = this.application()?.home_url;
        if (home) {
          window.location.assign(home);
        } else {
          this.busy.set(false);
          this.goTo('expired');
        }
      },
    });
  }

  protected goHome(): void {
    const home = this.application()?.home_url;
    if (home) {
      window.location.assign(home);
    }
  }

  // ── The machinery ──────────────────────────────────────────────────────────

  protected goTo(step: Step): void {
    this._clearErrors();
    this.notice.set(null);
    this.info.set(null);
    this.step.set(step);
  }

  protected t(key: TranslationKey, params?: Record<string, string | number | null | undefined>): string {
    return this._i18n.t(key, params);
  }

  private _signIn(totp?: string): void {
    const pending = this._pending;
    if (!pending) {
      return;
    }

    const options: SignInOptions = { remember: this.remember() };
    const deviceToken = readDeviceToken();
    if (deviceToken) {
      options.device_token = deviceToken;
    }
    if (totp) {
      options.totp = totp;
      if (this.rememberDevice()) {
        options.remember_device = true;
      }
    }

    const call$: Observable<SignInResponse> =
      pending.kind === 'password'
        ? this._loginApi.password({ email: pending.email, password: pending.password, ...options })
        : pending.kind === 'code'
          ? this._loginApi.verifyCode({ email: pending.email, code: pending.code, ...options })
          : this._loginApi.google({ credential: pending.credential, language: this._i18n.language(), ...options });

    this.busy.set(true);
    call$.subscribe({
      next: (response) => {
        this.busy.set(false);
        storeDeviceToken(response.device_token);
        this._session.set(response.user);
        if (response.created) {
          this._toast.success(this.t('login.createdToast'));
        }
        this._continueAfterSignIn(response.user);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this._signInRefused(error);
      },
    });
  }

  /** Every refusal of a sign-in, turned into the step that helps. */
  private _signInRefused(error: ApiError): void {
    const pending = this._pending;

    switch (error.code) {
      case 'totp_required':
        this.totp.set('');
        this.goTo('totp');
        return;
      case 'totp_invalid':
        this.totp.set('');
        if (this.step() !== 'totp') {
          this.goTo('totp');
        }
        this.totpError.set(this.t('error.totpInvalid'));
        return;
      case 'email_not_confirmed':
        this.unconfirmedEmail.set(pending && pending.kind !== 'google' ? pending.email : this.email().trim());
        this.goTo('unconfirmed');
        return;
      case 'password_login_unavailable':
        this.goTo('other-way');
        return;
      case 'invalid_code':
        this.code.set('');
        this.codeError.set(this.t('error.invalidCode'));
        return;
      case 'invalid_credentials':
        this.password.set('');
        if (this.step() !== 'form') {
          this.goTo('form');
        }
        this.formError.set(this.t('error.invalidCredentials'));
        return;
    }

    // Google's own refusal means the token did not verify - not a dead link,
    // which is what the same code means everywhere else.
    const text = error.code === 'invalid_token' ? this.t('error.googleFailed') : this._errorText(error);

    if (this.step() === 'totp') {
      this.totpError.set(text);
    } else if (this.step() === 'code') {
      this.codeError.set(text);
    } else {
      if (this.step() !== 'form' && this.step() !== 'other-way') {
        this.goTo('form');
      }
      this.formError.set(text);
    }
  }

  private _continueAfterSignIn(user: AccountUser): void {
    if (this.request()) {
      this._accept();
    } else if (user.force_password_change) {
      this.goTo('change-password');
    } else {
      void this._router.navigateByUrl(safeNext(this.next()));
    }
  }

  private _accept(): void {
    const id = this.request();
    if (!id) {
      return;
    }

    this.busy.set(true);
    this._loginApi.accept(id).subscribe({
      next: ({ redirect }) => {
        this.step.set('continuing');
        // A full navigation, not the router: the redirect is the application's
        // own callback, on another origin, carrying the code.
        window.location.assign(redirect);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this._acceptRefused(error);
      },
    });
  }

  private _acceptRefused(error: ApiError): void {
    switch (error.code) {
      case 'no_session':
        this._session.clear();
        this.goTo('form');
        return;
      case 'request_expired':
        this.goTo('expired');
        return;
      case 'email_not_confirmed':
        this.unconfirmedEmail.set(this.user()?.email ?? this.email().trim());
        this.goTo('unconfirmed');
        return;
      case 'password_change_required':
        this.goTo('change-password');
        return;
      case 'access_denied':
        this.goTo('denied');
        return;
      case 'application_disabled':
        this.goTo('app-disabled');
        return;
      case 'account_disabled':
        // Switched off while this browser was still signed in: nothing to
        // retry, and the form is where the explanation belongs.
        this._session.clear();
        this.goTo('form');
        this.formError.set(this.t('error.accountDisabled'));
        return;
      case 'login_required': {
        // The application asked for fresh credentials (prompt=login or
        // max_age): the same person signs in again, then this accepts again.
        const user = this.user();
        if (user) {
          this.email.set(user.email);
        }
        this._pending = null;
        this.password.set('');
        this.goTo('form');
        this.notice.set(this.t('login.againFor', { app: this.application()?.name ?? '' }));
        return;
      }
    }

    this._failed(error, () => this._accept());
  }

  private _failed(error: ApiError, retry: () => void): void {
    this.loadError.set(this._errorText(error));
    this._retry = retry;
    this.step.set('load-error');
  }

  /** The password just typed, when the sign-in was a password one - so a forced change does not ask for it twice. */
  private _knownPassword(): string | null {
    return this._pending?.kind === 'password' ? this._pending.password : null;
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this.t(key));
  }

  private _clearErrors(): void {
    this.formError.set(null);
    this.emailError.set(null);
    this.passwordError.set(null);
    this.codeError.set(null);
    this.totpError.set(null);
    this.changeErrors.set({});
  }

  private _startCooldown(): void {
    this._resendAt.set(Date.now() + RESEND_COOLDOWN_MS);
    this._now.set(Date.now());
    this._stopTicker();
    this._ticker = setInterval(() => {
      this._now.set(Date.now());
      if (this.resendIn() === 0) {
        this._stopTicker();
      }
    }, 1000);
  }

  private _stopTicker(): void {
    if (this._ticker) {
      clearInterval(this._ticker);
      this._ticker = null;
    }
  }
}
