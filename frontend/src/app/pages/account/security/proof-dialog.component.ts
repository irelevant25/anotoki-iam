import { ChangeDetectionStrategy, Component, ElementRef, Injector, afterNextRender, computed, inject, input, output, signal, viewChild } from '@angular/core';
import { AlertComponent, ButtonComponent, IconComponent } from '@anotoki/lib/ui';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { PasswordFieldComponent } from '@anotoki/lib/ui/forms';
import { Observable } from 'rxjs';
import { ApiError } from '../../../core/api/api-error';
import { AccountUser } from '../../../core/api/models';
import { passwordIsProof } from '../../../core/auth/fresh-sign-in';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { FreshSignInComponent } from '../fresh-sign-in.component';
import { IconName } from '../../../core/kit/icons';

/**
 * What a session alone cannot do, confirmed (docs/api.md, "The proof a
 * session alone cannot give"): deleting the account, switching password
 * sign-in off, connecting Google, switching two-factor on - a session left
 * open on somebody else's computer must not be enough for any of them.
 *
 * Where the account's password is a way in, the dialog asks for it ("Type
 * your password to confirm it is you"), and a wrong one is said on the field.
 * Elsewhere the proof is a sign-in of the last fifteen minutes: there is
 * nothing to type, the dialog says what is about to happen (`lead`), and a
 * sign-in older than that brings the way to sign in again, back to `anchor`
 * (FreshSignInComponent).
 *
 * The page gives the request (`send`) and its own words for the refusals it
 * knows better (`explain`); the dialog says the rest in the usual ones.
 * `done` hands over what the request answered - the account as it now is,
 * mostly; `refused` passes on every refusal - once the dialog has said it -
 * to a page that has more to do about one.
 */
@Component({
  selector: 'app-proof-dialog',
  imports: [TPipe, AlertComponent, ButtonComponent, DialogComponent, IconComponent, PasswordFieldComponent, FreshSignInComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './proof-dialog.component.html',
  styleUrl: './proof-dialog.component.scss',
})
export class ProofDialogComponent<T = AccountUser> {
  readonly heading = input.required<string>();
  /** What the dialog says where no password is asked for; null: the heading alone. */
  readonly lead = input<string | null>(null);
  /** The words on the button that sends it. */
  readonly action = input.required<string>();
  readonly actionIcon = input<IconName | null>(null);
  /** For what cannot be taken back with a click: a red button. */
  readonly danger = input(false);
  /** The part of the page signing in again comes back to (an element's id). */
  readonly anchor = input.required<string>();
  /** The request: with the current password where it is the proof, without one elsewhere. */
  readonly send = input.required<(password: string | undefined) => Observable<T>>();
  /** The page's own words for a refusal, or null for the usual ones. */
  readonly explain = input<(error: ApiError) => string | null>(() => null);

  readonly done = output<T>();
  readonly refused = output<ApiError>();
  readonly closed = output<void>();

  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _injector = inject(Injector);
  private readonly _field = viewChild(PasswordFieldComponent);
  private readonly _actionButton = viewChild('actionButton', { read: ElementRef<HTMLButtonElement> });

  protected readonly email = computed(() => this._session.user()?.email ?? '');
  protected readonly withPassword = computed(() => passwordIsProof(this._session.user()));

  protected readonly password = signal('');
  protected readonly passwordError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  /** The sign-in is too old to stand for the person (`reauthentication_required`): they sign in again first. */
  protected readonly freshSignIn = signal(false);
  protected readonly busy = signal(false);

  protected submit(): void {
    if (this.busy() || this.freshSignIn()) {
      return;
    }
    this.passwordError.set(null);
    this.formError.set(null);

    const withPassword = this.withPassword();
    const password = this.password();
    if (withPassword && !password) {
      this.passwordError.set(this._i18n.t('common.required'));
      this._refocus();
      return;
    }

    this.busy.set(true);
    this.send()(withPassword ? password : undefined).subscribe({
      next: (user) => {
        this.busy.set(false);
        this.done.emit(user);
      },
      error: (error: ApiError) => this._refused(error),
    });
  }

  /** A refusal, said where it belongs: the field's own, above it - or, for a sign-in too old, the way to a fresh one. */
  private _refused(error: ApiError): void {
    this.busy.set(false);
    this.password.set('');

    const own = this.explain()(error);
    if (own === null && error.code === 'reauthentication_required') {
      this.freshSignIn.set(true);
      if (this.withPassword()) {
        // Password sign-in was switched off elsewhere: the account as it now is takes the field away.
        this._session.refresh().subscribe({ error: () => undefined });
      }
    } else if (own === null && error.code === 'wrong_password' && this.withPassword()) {
      this.passwordError.set(errorText(error, (key) => this._i18n.t(key)));
      this._refocus();
    } else {
      if (own === null && error.code === 'wrong_password') {
        // The account signs in with a password after all - switched on elsewhere: the account as it now is brings the field.
        this._session.refresh().subscribe({ error: () => undefined });
      }
      this.formError.set(own ?? errorText(error, (key) => this._i18n.t(key)));
      this._refocus();
    }

    this.refused.emit(error);
  }

  /** The button a request went from is switched off while it is out, and the focus goes with it: back to the field, or the button. */
  private _refocus(): void {
    afterNextRender(() => (this.withPassword() ? this._field()?.focus() : this._actionButton()?.nativeElement.focus()), { injector: this._injector });
  }
}
