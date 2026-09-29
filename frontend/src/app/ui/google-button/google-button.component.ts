import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, afterRenderEffect, inject, input, output, signal, untracked, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { GoogleSignInService } from '../../core/google/google-sign-in.service';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { ThemeService } from '../../core/theme/theme.service';

/**
 * Google's own sign-in button, or nothing at all. Ported from the genshin site.
 *
 * Nothing at all is the ordinary case rather than a failure: a deployment
 * with Google switched off draws no button, and neither does one where the
 * script could not be fetched. Everything around it stays usable either way,
 * which is why it draws no placeholder and reports no error. `divider` puts
 * an "or" rule above it - only once the button is really there, so a page
 * without Google has no rule leading to nothing.
 *
 * The button follows the page: it is drawn again in the page's language and
 * theme when either changes, so a Slovak page does not carry an English
 * Google button and a dark one no glaring white pill.
 *
 * What comes out is the id token Google handed the browser. It means nothing
 * until the server has checked it was minted for this deployment.
 */
@Component({
  selector: 'app-google-button',
  imports: [TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.is-ready]': 'ready()' },
  templateUrl: './google-button.component.html',
  styleUrl: './google-button.component.scss',
})
export class GoogleButtonComponent {
  private readonly _google = inject(GoogleSignInService);
  private readonly _i18n = inject(TranslationService);
  private readonly _theme = inject(ThemeService);

  readonly text = input<'signin_with' | 'signup_with' | 'continue_with'>('signin_with');
  readonly divider = input(false);

  /** The id token, once somebody has chosen an account. */
  readonly credential = output<string>();

  protected readonly ready = signal(false);

  /** Optional on purpose: the button is drawn from a signal-driven render effect, which simply runs again once the view exists. */
  private readonly _host = viewChild<ElementRef<HTMLElement>>('host');
  private readonly _element = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly _clientId = toSignal(this._google.clientId(), { initialValue: null });
  private readonly _onCredential = (credential: string) => this.credential.emit(credential);

  constructor() {
    // After rendering, not during it: the width below is a layout read, and
    // made while the page around this button is still being put together it
    // forces a style pass on half-bound elements - the sign-in button then
    // faded from its plain look to its blue one as the form appeared.
    afterRenderEffect(() => {
      const clientId = this._clientId();
      const locale = this._i18n.effectiveLanguage();
      const theme = this._theme.resolved();
      const text = this.text();
      const host = this._host()?.nativeElement;

      if (!clientId || !host) {
        return;
      }

      untracked(() => {
        // As wide as whatever holds this component (this element itself is
        // hidden until the button is drawn), within the 200-400 px GIS accepts.
        const width = Math.max(200, Math.min(400, Math.floor(this._element.parentElement?.clientWidth ?? 320)));
        void this._google.renderButton(host, clientId, { text, locale, theme, width }, this._onCredential).then((drawn) => this.ready.set(drawn));
      });
    });

    // The callback is global to the page, so a button that has gone away has
    // to stop owning it - see GoogleSignInService.
    inject(DestroyRef).onDestroy(() => this._google.release(this._onCredential));
  }
}
