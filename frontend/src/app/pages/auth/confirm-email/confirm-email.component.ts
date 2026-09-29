import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { ContinueTo } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { dropQueryParam, isInternalPath, looksLikeEmail } from '../auth-helpers';

type Step = 'working' | 'done' | 'failed' | 'missing';

/**
 * /confirm-email?token= - where the confirmation link lands.
 *
 * The token is taken out of the address bar before anything else, so it does
 * not stay in the history or travel on with a copied URL. Opening the link
 * signs the person in (holding the mailbox is the proof), and the page offers
 * the way back to the application the registration started from, if any.
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

  protected readonly step = signal<Step>('working');
  protected readonly continueTo = signal<ContinueTo | null>(null);
  protected readonly failure = signal('');
  protected readonly email = signal('');
  protected readonly emailError = signal<string | null>(null);
  protected readonly info = signal<string | null>(null);
  protected readonly busy = signal(false);

  ngOnInit(): void {
    const token = this.token();
    dropQueryParam('token');

    if (!token) {
      this.step.set('missing');
      return;
    }

    this._loginApi.confirm(token).subscribe({
      next: (response) => {
        this._session.set(response.user);
        this.continueTo.set(response.continue_to);
        this.step.set('done');
      },
      error: (error: ApiError) => {
        this.failure.set(error.code === 'invalid_token' ? this._i18n.t('confirm.failedText') : errorText(error, (key) => this._i18n.t(key)));
        this.step.set('failed');
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
}
