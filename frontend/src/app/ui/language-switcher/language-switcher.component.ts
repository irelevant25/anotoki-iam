import { ChangeDetectionStrategy, Component, ElementRef, Injector, afterNextRender, computed, inject, input, linkedSignal, output, signal, viewChild, viewChildren } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { IconComponent } from '../icon/icon.component';
import { SelectComponent, SelectOption } from '../select/select.component';
import { ToastService } from '../toast/toast.service';
import { uniqueId } from '../unique-id';

/** Up to this many languages are segments side by side; more would not fit beside the theme toggle on a phone. */
const MOST_SEGMENTS = 4;

/**
 * EN | SK, for the sign-in pages: one segment per language on offer.
 *
 * Each option shows its code and is named in its own language ("Slovenčina",
 * not "Slovak") and carries its own `lang`, so it is read out correctly and is
 * recognisable to somebody who does not read the language currently on screen.
 * The choice is remembered on this device; on the account pages the top bar
 * saves it to the account too (`changed`), as the overview's select does.
 *
 * The languages come from the database, so there may be more than two: above
 * four the segments give way to a select of the native names. With fewer than
 * two there is nothing to choose and nothing is drawn - which is also the case
 * while the pages read the compiled English, before any bundle has arrived.
 *
 * `compact` is the top bar's form (the anotoki family's bar): one 40 px button
 * with the current language's code, opening a menu of the languages under
 * their own names - a segmented control has no room there on a 360 px phone.
 * It is a menu button like the bar's own menus: focus into the menu on the
 * language in use, arrow keys, Home and End, Escape and a click outside to
 * close it, and Tab or Shift+Tab close it with the focus back on the button
 * (left to the browser, the focus would land on the next item of a menu still
 * drawn - with no zone it goes only at the next render - and then on <body>).
 *
 * A language is switched to only once its words have come. When they cannot be
 * had the page stays as it is, and so does this.
 */
@Component({
  selector: 'app-language-switcher',
  imports: [TPipe, IconComponent, SelectComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './language-switcher.component.html',
  styleUrl: './language-switcher.component.scss',
  host: {
    '[class.is-compact]': 'compact()',
    '(document:click)': 'onDocumentClick($event)',
    '(document:keydown.escape)': 'close(true)',
  },
})
export class LanguageSwitcherComponent {
  /** The top bar's form: a button and a menu, 40 px wide. */
  readonly compact = input(false);

  protected readonly i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);
  private readonly _host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly _injector = inject(Injector);

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

  /** The compact form's menu is open. */
  protected readonly open = signal(false);
  protected readonly buttonId = uniqueId('language-button');
  protected readonly menuId = uniqueId('language-menu');
  /** The language on the page as its speakers write it; its code while the list does not name it. */
  protected readonly currentName = computed(() => {
    const code = this.i18n.language();
    return this.languages().find((language) => language.code === code)?.native_name ?? code.toUpperCase();
  });
  /**
   * What the compact button shows: the code of the language on the page. Its
   * name for a screen reader holds the code too ("Language: Slovenčina (SK)"):
   * the words on a button are part of its name (WCAG 2.5.3, label in name),
   * so "click SK" finds it by voice.
   */
  protected readonly currentCode = computed(() => this.i18n.language().toUpperCase());

  /** The language now on the page - emitted only once it is. */
  readonly changed = output<string>();

  private readonly _buttons = viewChildren<ElementRef<HTMLButtonElement>>('option');
  private readonly _button = viewChild<ElementRef<HTMLButtonElement>>('button');
  private readonly _menu = viewChild<ElementRef<HTMLElement>>('menu');

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

  protected toggle(): void {
    if (this.open()) {
      this.close(true);
      return;
    }
    this.open.set(true);
    // Into the menu once it is drawn, onto the language in use. Drawn means
    // after the next render: with no zone, a microtask runs before it.
    afterNextRender(
      () => {
        const items = this._menuItems();
        (items.find((item) => item.getAttribute('aria-checked') === 'true') ?? items[0])?.focus();
      },
      { injector: this._injector },
    );
  }

  /** Closes the compact form's menu; `returnFocus`: back to its button (Escape, Tab, a choice). */
  close(returnFocus: boolean): void {
    if (!this.open()) {
      return;
    }
    this.open.set(false);
    if (returnFocus) {
      this._button()?.nativeElement.focus();
    }
  }

  /** A language from the compact form's menu: the menu closes at once; a language whose words cannot be had is said. */
  protected async chooseFromMenu(code: string): Promise<void> {
    this.close(true);
    if (code === this.i18n.language() && !this.switching()) {
      return;
    }
    if (!(await this.choose(code)) && this.i18n.language() !== code && this._pending === 0) {
      this._toast.error(this.i18n.t('error.generic'));
    }
  }

  protected onDocumentClick(event: MouseEvent): void {
    if (this.open() && !this._host.nativeElement.contains(event.target as Node)) {
      this.close(false);
    }
  }

  protected onMenuKeydown(event: KeyboardEvent): void {
    const items = this._menuItems();
    const index = items.indexOf(document.activeElement as HTMLElement);
    let next = -1;
    if (event.key === 'ArrowDown') {
      next = (index + 1) % items.length;
    } else if (event.key === 'ArrowUp') {
      next = (index - 1 + items.length) % items.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = items.length - 1;
    } else if (event.key === 'Tab') {
      event.preventDefault();
      this.close(true);
      return;
    }
    if (next >= 0) {
      event.preventDefault();
      items[next]?.focus();
    }
  }

  private _menuItems(): HTMLElement[] {
    const menu = this._menu()?.nativeElement;
    return menu ? Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitemradio"]')) : [];
  }
}
