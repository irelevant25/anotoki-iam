import { ChangeDetectionStrategy, Component, ElementRef, inject, output, viewChildren } from '@angular/core';
import { LANGUAGES, Language } from '../../core/api/models';
import { TranslationKey } from '../../core/i18n/en';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';

const NAMES: Record<Language, TranslationKey> = { en: 'lang.en', sk: 'lang.sk' };

/**
 * EN | SK, for the sign-in pages.
 *
 * Each option is named in its own language ("Slovenčina", not "Slovak") and
 * carries its own `lang`, so it is read out correctly and is recognisable to
 * somebody who does not read the language currently on screen. The choice is
 * remembered on this device; the account page saves it to the account instead.
 */
@Component({
  selector: 'app-language-switcher',
  imports: [TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './language-switcher.component.html',
  styleUrl: './language-switcher.component.scss',
})
export class LanguageSwitcherComponent {
  protected readonly i18n = inject(TranslationService);
  protected readonly languages = LANGUAGES;
  protected readonly names = NAMES;

  readonly changed = output<Language>();

  private readonly _buttons = viewChildren<ElementRef<HTMLButtonElement>>('option');

  protected choose(language: Language): void {
    this.i18n.choose(language);
    this.changed.emit(language);
  }

  protected onKeydown(event: KeyboardEvent): void {
    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 0;
    if (!step) {
      return;
    }
    event.preventDefault();
    const index = LANGUAGES.indexOf(this.i18n.language());
    const next = (index + step + LANGUAGES.length) % LANGUAGES.length;
    this.choose(LANGUAGES[next]);
    this._buttons()[next]?.nativeElement.focus();
  }
}
