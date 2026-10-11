import { ChangeDetectionStrategy, Component, computed, inject, output, signal } from '@angular/core';
import { DomSanitizer, SafeUrl } from '@angular/platform-browser';
import { AlertComponent, ButtonComponent, IconComponent, SpinnerComponent } from '@anotoki/lib/ui';
import { CopyButtonComponent } from '@anotoki/lib/ui/copy';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { CodeInputComponent } from '@anotoki/lib/ui/forms';
import { QrCodeComponent } from '@anotoki/lib/ui/qr';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { RecoveryCodes, TwoFactorSetup } from '../../../core/api/models';
import { passwordIsProof } from '../../../core/auth/fresh-sign-in';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { FreshSignInComponent } from '../fresh-sign-in.component';
import { ProofDialogComponent } from './proof-dialog.component';
import { RecoveryCodesComponent } from './recovery-codes.component';
import { SECURITY_ANCHORS } from './security-anchors';

type Step = 'loading' | 'error' | 'sign-in-again' | 'scan' | 'codes';

/**
 * Turning two-factor on, in the order that cannot lock anybody out:
 *
 *   1. the backend issues a secret, shown as a QR code and as text;
 *   2. a code computed from it comes back as proof it was really scanned -
 *      only then does the account start requiring codes;
 *   3. the recovery codes, shown once, and the dialog stays until the person
 *      says they have them.
 *
 * Switching it on asks for the proof a session alone cannot give (docs/api.md):
 * with an authenticator of its own, a session's holder would take the
 * recovery codes and keep the owner out. Where the password is a way in, it
 * is asked for last, once the code is typed (ProofDialogComponent - the two go
 * in one request); elsewhere a sign-in of the last fifteen minutes is the
 * proof, and an older one brings the way to sign in again, back to the
 * two-factor part of the page (FreshSignInComponent). The server says so
 * before it makes a secret, so that is said in place of the QR code - nothing
 * is scanned that would have to be thrown away - and again at the end, for a
 * sign-in that grew old while the code was being typed.
 */
@Component({
  selector: 'app-two-factor-setup-dialog',
  imports: [
    TPipe,
    AlertComponent,
    ButtonComponent,
    CodeInputComponent,
    CopyButtonComponent,
    DialogComponent,
    IconComponent,
    QrCodeComponent,
    SpinnerComponent,
    FreshSignInComponent,
    ProofDialogComponent,
    RecoveryCodesComponent,
  ],
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
  protected readonly anchor = SECURITY_ANCHORS.twoFactor;
  /** The code is typed and the password is the proof: the dialog that asks for it, over this one. */
  protected readonly askProof = signal(false);
  /** No password to ask for, and a sign-in too old to stand for the person (`reauthentication_required`): they sign in again first. */
  protected readonly freshSignIn = signal(false);

  /** What the proof dialog sends: the code with the password - in one request, the proof read first. */
  protected readonly enableWith = (password: string | undefined) => this._accountApi.enableTwoFactor(this.code(), password);

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
        if (error.code === 'reauthentication_required') {
          this.step.set('sign-in-again');
          return;
        }
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
    if (this.freshSignIn()) {
      return;
    }
    this.codeError.set(null);
    if (this.code().length !== 6) {
      this.codeError.set(this._i18n.t('common.required'));
      return;
    }

    if (passwordIsProof(this._session.user())) {
      this.askProof.set(true);
      return;
    }

    this.busy.set(true);
    this._accountApi.enableTwoFactor(this.code()).subscribe({
      next: (response) => {
        this.busy.set(false);
        this.enabled(response);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        if (error.code === 'reauthentication_required') {
          this.freshSignIn.set(true);
        } else {
          this._codeRefused(error);
        }
      },
    });
  }

  /** On: the recovery codes, this once. */
  protected enabled(response: RecoveryCodes): void {
    this.askProof.set(false);
    this.codes.set(response.recovery_codes);
    this.step.set('codes');
  }

  /**
   * A refusal the proof dialog has said. What is about the proof - the
   * password, a sign-in too old, too many attempts - stays there; what is
   * about the code (one that went stale while the password was typed, say)
   * goes back to the code's field.
   */
  protected proofRefused(error: ApiError): void {
    if (!['wrong_password', 'reauthentication_required', 'too_many_attempts'].includes(error.code)) {
      this.askProof.set(false);
      this._codeRefused(error);
    }
  }

  private _codeRefused(error: ApiError): void {
    this.code.set('');
    this.codeError.set(error.code === 'totp_invalid' ? this._i18n.t('error.totpInvalid') : errorText(error, (key) => this._i18n.t(key)));
  }
}
