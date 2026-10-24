import { ChangeDetectionStrategy, Component, ElementRef, afterNextRender, inject, input, signal, viewChild } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { ApiError } from '../../../api/api-error';
import { FreshSignInService } from '../../../shared/auth/fresh-sign-in';
import { errorText } from '../../../shared/i18n/error-text';
import { TPipe } from '../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../shared/i18n/translation.service';

/**
 * "Sign in again first": what an account page shows for `403
 * reauthentication_required` - the words, and the button that signs out and
 * opens the sign-in page, back to `anchor` on this page afterwards
 * (FreshSignInService). The one way past it, wherever it is asked: deleting
 * the account, a first password, password sign-in switched on, Google
 * connected.
 *
 * It appears because of something just tried, whose button went quiet while
 * the request was out, so it takes the focus.
 */
@Component({
  selector: 'app-fresh-sign-in',
  imports: [TPipe, AlertComponent, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './fresh-sign-in.component.html',
  styleUrl: './fresh-sign-in.component.scss',
})
export class FreshSignInComponent {
  /** The id of the part of the page to come back to. */
  readonly anchor = input.required<string>();

  private readonly _freshSignIn = inject(FreshSignInService);
  private readonly _i18n = inject(TranslationService);
  private readonly _button = viewChild.required('button', { read: ElementRef<HTMLButtonElement> });

  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
    afterNextRender(() => this._button().nativeElement.focus());
  }

  protected signIn(): void {
    this.busy.set(true);
    this.error.set(null);
    this._freshSignIn.signInAgain(this.anchor()).subscribe({
      error: (error: ApiError) => {
        this.busy.set(false);
        this.error.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }
}
