import {
  ChangeDetectionStrategy,
  Component,
  DOCUMENT,
  DestroyRef,
  ElementRef,
  Injector,
  afterNextRender,
  afterRenderEffect,
  computed,
  inject,
  input,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ThemeService } from '@anotoki/lib/shell';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { GoogleButtonOptions, GoogleSignInService } from '../../core/google/google-sign-in.service';
import { TranslationKey } from '../../core/i18n/en';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';

/** How long a press waits for Google's script before the button says Google could not be reached. */
export const GOOGLE_LOAD_TIMEOUT_MS = 15_000;

/** The IAM's words on its own button, for each of the texts Google's button knows. */
const BUTTON_WORDS = {
  signin_with: 'google.signIn',
  signup_with: 'google.signUp',
  continue_with: 'google.continue',
} as const satisfies Record<GoogleButtonOptions['text'], TranslationKey>;

let nextId = 0;

/**
 * "Sign in with Google": the IAM's own button first, Google's after a press.
 * Ported from the genshin site, and changed by the owner's decision of
 * 2026-10-14: Google is contacted only when somebody asks for it.
 *
 * - Drawn by the page alone - no request to Google -: a real `<button>` in
 *   Google's published branding for a sign-in button (the "G" in its colours,
 *   drawn here, never fetched; Google's light and dark fills; a pill), in the
 *   page's language and theme, the kit's height - 44 px, a finger's target.
 * - A press fetches Google's script (GoogleSignInService.load(): once a page
 *   life) and draws Google's own button in the same place - only Google's
 *   carries its account chooser, so choosing an account is a second press, on
 *   Google's button. A live region says it is ready; the focus moves onto the
 *   frame that holds it (Google's button is in Google's frame, where no page
 *   can put the focus): the next Tab is Google's button, Enter opens it.
 * - If the script does not come (blocked, offline, or not within
 *   GOOGLE_LOAD_TIMEOUT_MS), the IAM's button stays and a line under it says
 *   so; a press tries again. Everything around it works as before.
 *
 * Nothing at all is drawn while Google sign-in is off - the ordinary case
 * rather than a failure - and `divider` puts an "or" rule above the button
 * only when there is one, so a page without Google has no rule leading to
 * nothing.
 *
 * Google's button follows the page: drawn again in the page's language and
 * theme when either changes, so a Slovak page does not carry an English
 * Google button and a dark one no glaring white pill.
 *
 * What comes out is the id token Google handed the browser. It means nothing
 * until the server has checked it was minted for this deployment.
 */
@Component({
  selector: 'app-google-button',
  imports: [SpinnerComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'app-google-button',
    '[class.app-google-button--on]': '!!clientId()',
    '[class.app-google-button--dark]': 'dark()',
    '[class.app-google-button--google]': 'phase() === "google"',
  },
  templateUrl: './google-button.component.html',
  styleUrl: './google-button.component.scss',
})
export class GoogleButtonComponent {
  private readonly _google = inject(GoogleSignInService);
  private readonly _i18n = inject(TranslationService);
  private readonly _theme = inject(ThemeService);
  private readonly _document = inject(DOCUMENT);
  private readonly _injector = inject(Injector);

  readonly text = input<GoogleButtonOptions['text']>('signin_with');
  readonly divider = input(false);

  /** The id token, once somebody has chosen an account. */
  readonly credential = output<string>();

  /** The client id, or null while Google sign-in is off (and until the config is known). */
  protected readonly clientId = toSignal(this._google.clientId(), { initialValue: null });
  /** `ours` until a press, `loading` while Google's script is on its way, `google` once Google's button is drawn. */
  protected readonly phase = signal<'ours' | 'loading' | 'google'>('ours');
  /** The last press could not bring Google's button. */
  protected readonly failed = signal(false);
  /** Google's button is drawn after a press: the live region says so. */
  protected readonly ready = signal(false);
  protected readonly dark = computed(() => this._theme.resolved() === 'dark');
  protected readonly words = computed(() => BUTTON_WORDS[this.text()]);
  protected readonly failedId = `app-google-button-failed-${nextId++}`;

  private readonly _place = viewChild<ElementRef<HTMLElement>>('place');
  private readonly _frame = viewChild<ElementRef<HTMLElement>>('frame');
  private readonly _gis = viewChild<ElementRef<HTMLElement>>('gis');
  private readonly _onCredential = (credential: string) => this.credential.emit(credential);
  /** What Google's button was last drawn with, so that only a change draws it again. */
  private _drawnWith: string | null = null;
  /** The IAM's button's width at the press: Google's is drawn as wide, every time. */
  private _width = 0;
  private _destroyed = false;

  constructor() {
    // Google's button follows the page's theme and language once it is there.
    afterRenderEffect(() => {
      if (this.phase() !== 'google') {
        return;
      }
      const options = { text: this.text(), locale: this._i18n.effectiveLanguage(), theme: this._theme.resolved() };
      untracked(() => {
        if (this._drawnWith !== JSON.stringify(options) && !this._draw(options)) {
          // Back to the IAM's button: the live region no longer says Google's is ready.
          this.phase.set('ours');
          this.ready.set(false);
          this.failed.set(true);
        }
      });
    });

    // The callback is global to the page, so a button that has gone away has
    // to stop owning it - see GoogleSignInService.
    inject(DestroyRef).onDestroy(() => {
      this._destroyed = true;
      this._google.release(this._onCredential);
    });
  }

  /** A press on the IAM's button: Google's script, then Google's button in its place. */
  protected async press(): Promise<void> {
    if (this.phase() !== 'ours' || !this.clientId()) {
      return;
    }
    // A layout read, but in an event handler: the page is drawn by now.
    this._width = this._place()?.nativeElement.clientWidth ?? 0;
    this.failed.set(false);
    this.phase.set('loading');

    const loaded = await this._loadInTime();
    if (this._destroyed) {
      return;
    }
    if (!loaded || !this._draw({ text: this.text(), locale: this._i18n.effectiveLanguage(), theme: this._theme.resolved() })) {
      this.phase.set('ours');
      this.ready.set(false);
      this.failed.set(true);
      return;
    }

    // The focus follows the button it was on - or that a click left on the
    // page itself -, never away from wherever somebody has gone meanwhile.
    const active = this._document.activeElement;
    const moveFocus = !active || active === this._document.body || !!this._place()?.nativeElement.contains(active);
    this.phase.set('google');
    this.ready.set(true);
    if (moveFocus) {
      afterNextRender({ write: () => this._frame()?.nativeElement.focus() }, { injector: this._injector });
    }
  }

  /** Google's script, or false if it has not come within GOOGLE_LOAD_TIMEOUT_MS (a later press waits for it again). */
  private _loadInTime(): Promise<boolean> {
    return new Promise((resolve) => {
      const timer = setTimeout(() => resolve(false), GOOGLE_LOAD_TIMEOUT_MS);
      void this._google.load().then((loaded) => {
        clearTimeout(timer);
        resolve(loaded);
      });
    });
  }

  /** Draws Google's button into the frame: false when Google's script is not there or will not draw. */
  private _draw({ text, locale, theme }: Omit<GoogleButtonOptions, 'width'>): boolean {
    const clientId = this.clientId();
    const gis = this._gis()?.nativeElement;
    if (!clientId || !gis) {
      return false;
    }
    // As wide as the IAM's button was, within the 200-400 px Google's accepts.
    const width = Math.max(200, Math.min(400, Math.floor(this._width || 320)));
    if (!this._google.drawButton(gis, clientId, { text, locale, theme, width }, this._onCredential)) {
      return false;
    }
    this._drawnWith = JSON.stringify({ text, locale, theme });
    return true;
  }
}
