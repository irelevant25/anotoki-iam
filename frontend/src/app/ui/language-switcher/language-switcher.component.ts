import { ChangeDetectionStrategy, Component, ElementRef, computed, inject, linkedSignal, output, signal, viewChildren } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { SelectComponent, SelectOption } from '../select/select.component';

/** Up to this many languages are segments side by side; more would not fit beside the theme toggle on a phone. */
const MOST_SEGMENTS = 4;

/**
 * EN | SK, for the sign-in pages: one segment per language on offer.
 *
 * Each option shows its code and is named in its own language ("Slovenčina",
 * not "Slovak") and carries its own `lang`, so it is read out correctly and is
 * recognisable to somebody who does not read the language currently on screen.
 * The choice is remembered on this device; the account page saves it to the
 * account instead.
 *
 * The languages come from the database, so there may be more than two: above
 * four the segments give way to a select of the native names. With fewer than
 * two there is nothing to choose and nothing is drawn - which is also the case
 * while the pages read the compiled English, before any bundle has arrived.
 *
 * A language is switched to only once its words have come. When they cannot be
 * had the page stays as it is, and so does this.
 */
@Component({
  selector: 'app-language-switcher',
  imports: [TPipe, SelectComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './language-switcher.component.html',
  styleUrl: './language-switcher.component.scss',
})
export class LanguageSwitcherComponent {
  protected readonly i18n = inject(TranslationService);

  protected readonly languages = this.i18n.languages;
  protected readonly segmented = computed(() => this.languages().length <= MOST_SEGMENTS);
  protected readonly options = computed<SelectOption[]>(() => this.languages().map((language) => ({ value: language.code, label: language.native_name })));
  /** What the select shows: the language on the page, put back there when another could not be had. */
  protected readonly selected = linkedSignal(() => this.i18n.language());
  /**
   * The segment a Tab lands on: the language on the page, or the first one
   * when that is not among the offered (switched off a moment ago) - a group
   * nobody can tab into would be out of a keyboard's reach.
   */
  protected readonly focusable = computed(() => {
    const current = this.i18n.language();
    const languages = this.languages();
    return languages.some((language) => language.code === current) ? current : languages[0]?.code;
  });
  /** The chosen language's words are on their way. */
  protected readonly switching = signal(false);

  /** The language now on the page - emitted only once it is. */
  readonly changed = output<string>();

  private readonly _buttons = viewChildren<ElementRef<HTMLButtonElement>>('option');

  /** Switches still on their way: two clicks in a row are two. */
  private _pending = 0;

  /**
   * The service is told even when the language is the one already on the page:
   * that is how a click back takes back a switch whose words are still on
   * their way.
   */
  protected async choose(code: string): Promise<boolean> {
    const before = this.i18n.language();
    this._pending++;
    this.switching.set(true);
    const switched = await this.i18n.choose(code);
    this.switching.set(--this._pending > 0);
    this.selected.set(this.i18n.language());
    if (switched && code !== before) {
      this.changed.emit(code);
    }
    return switched;
  }

  protected async onKeydown(event: KeyboardEvent): Promise<void> {
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
    const languages = this.languages();
    if (!step || !languages.length) {
      return;
    }
    event.preventDefault();
    const index = languages.findIndex((language) => language.code === this.i18n.language());
    // From a language that is not in the list, the first arrow lands on its first or last segment.
    const next = index < 0 ? (step > 0 ? 0 : languages.length - 1) : (index + step + languages.length) % languages.length;
    // Focus follows the choice, as in any group of radio buttons - and stays
    // put when the language could not be had.
    if (await this.choose(languages[next].code)) {
      this._buttons()[next]?.nativeElement.focus();
    }
  }
}
