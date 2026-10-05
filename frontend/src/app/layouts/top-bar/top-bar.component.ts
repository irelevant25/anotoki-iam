import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, Injector, Signal, afterNextRender, computed, effect, inject, input, signal, untracked, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router, RouterLink } from '@angular/router';
import { filter, map } from 'rxjs';
import { AccountApi } from '../../core/api/account.api';
import { ApiError } from '../../core/api/api-error';
import { LoginApi } from '../../core/api/login.api';
import { SessionService } from '../../core/auth/session.service';
import { TranslationKey } from '../../core/i18n/en';
import { errorText } from '../../core/i18n/error-text';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { BrandComponent } from '../../ui/brand/brand.component';
import { ButtonComponent } from '../../ui/button/button.component';
import { IconComponent } from '../../ui/icon/icon.component';
import { LanguageSwitcherComponent } from '../../ui/language-switcher/language-switcher.component';
import { SpinnerComponent } from '../../ui/spinner/spinner.component';
import { ThemeToggleComponent } from '../../ui/theme-toggle/theme-toggle.component';
import { ToastService } from '../../ui/toast/toast.service';

/** A section in the bar: its words (a key), where it goes, and the addresses under it that are part of it. */
export interface BarLink {
  key: TranslationKey;
  path: string;
  /** Address beginnings that belong to the section too. */
  under: readonly string[];
}

/** The account pages: the IAM's own sections, and the only links the bar has. */
export const ACCOUNT_LINKS: readonly BarLink[] = [
  { key: 'account.nav.overview', path: '/account', under: [] },
  { key: 'account.nav.security', path: '/account/security', under: [] },
  { key: 'account.nav.sessions', path: '/account/sessions', under: [] },
  { key: 'account.nav.applications', path: '/account/applications', under: [] },
  { key: 'account.nav.tokens', path: '/account/tokens', under: [] },
];

/**
 * Up to this width the bar is a phone's: the links and the person's menu fold
 * into one menu button. Measured, not guessed (headless Chrome, 2026-10): the
 * row with the five account links, the language, the theme and the person's
 * round button needs 1010 px in English and 1005 in Slovak - plus a desktop
 * browser's scrollbar (up to 17 px, which a media query counts and the row
 * does not have) and a little for a string reworded in the panel. Above it
 * only the person's name gives way (an ellipsis; hidden up to 1240 px). The
 * same numbers are in the scss.
 */
export const PHONE_BAR_MAX = 1040;

/** Which of the bar's two menus is open: the person's (account) or the phone's (links and account). */
type OpenMenu = 'account' | 'phone' | null;

/**
 * The bar across the top of the account pages and the admin panel - the
 * anotoki family's one bar (the survey site's `layouts/top-bar` is the
 * reference), in one row, in this order: the brand (the mark, "anotoki" and
 * "account" or "admin panel", linking home); the account pages as links; then the
 * language (on the account pages, which are translated - not in the admin
 * panel, which is English), the theme, and the person's menu (name,
 * address, role; the admin panel for the IAM's administrators, or back to
 * the account from it; signing out) - or a link to sign in.
 *
 * The family's menu has "Your anotoki account" for the IAM's account page;
 * here that is this site, so the entry is left out: the account pages are the
 * bar's own links (Overview is their home), and in the admin panel the way
 * to them is the menu's "Back to your account".
 *
 * The admin panel keeps its own section navigation (a sidebar, a strip under
 * the bar on a phone - `layouts/shell`): eleven sections are a list, not a
 * row of links. Its bar has no links of its own.
 *
 * On a phone (up to PHONE_BAR_MAX) the brand is the mark and the area alone,
 * and the links and the person's menu are inside one menu button at the right,
 * after the language and the theme. A crossing of that width closes whatever
 * menu is open.
 *
 * Both menus: focus into the menu when it opens, arrow keys, Home and End
 * between its items, Escape and a click outside to close it - Escape gives the
 * focus back to the button. Tab and Shift+Tab close it with the focus on the
 * button too, so the next Tab moves on from there (not to <body>).
 *
 * Every word a reader of the account pages meets is a key; in the admin
 * panel the `t` pipe answers in English (`effectiveLanguage`), and what only
 * the panel shows is written here in English, as the panel's templates are.
 */
@Component({
  selector: 'app-top-bar',
  imports: [NgTemplateOutlet, RouterLink, BrandComponent, ButtonComponent, IconComponent, LanguageSwitcherComponent, SpinnerComponent, ThemeToggleComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './top-bar.component.html',
  styleUrl: './top-bar.component.scss',
  host: {
    '(document:click)': 'onDocumentClick($event)',
    '(document:keydown.escape)': 'closeMenu(true)',
  },
})
export class TopBarComponent {
  /** Which part of the IAM the page belongs to: what the brand says and links to, the links, and where the other part is. */
  readonly area = input.required<'account' | 'admin'>();

  private readonly _session = inject(SessionService);
  private readonly _loginApi = inject(LoginApi);
  private readonly _accountApi = inject(AccountApi);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _i18n = inject(TranslationService);
  private readonly _host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly _injector = inject(Injector);

  protected readonly user = this._session.user;
  protected readonly isAdmin = this._session.isAdmin;
  /** Which menu is open, if one is. */
  protected readonly openMenu = signal<OpenMenu>(null);
  protected readonly signingOut = signal(false);
  protected readonly initial = computed(() => (this.user()?.username.trim()[0] ?? '?').toUpperCase());
  /** The bar of a phone: the links and the person's menu inside the menu button. */
  protected readonly phone = this._matches(`(max-width: ${PHONE_BAR_MAX}px)`);
  protected readonly links = computed(() => (this.area() === 'account' ? ACCOUNT_LINKS : []));
  /** The language: on the account pages (the switcher draws nothing with fewer than two). */
  protected readonly chooser = computed(() => this.area() === 'account');

  /** The address of the page, without its query: which link is the page's. */
  private readonly _path = toSignal(
    this._router.events.pipe(
      filter((event) => event instanceof NavigationEnd),
      map(() => this._router.url),
    ),
    { initialValue: this._router.url },
  );
  protected readonly here = computed(() => this._path().split(/[?#]/)[0] || '/');

  private readonly _accountButton = viewChild<ElementRef<HTMLButtonElement>>('accountButton');
  private readonly _phoneButton = viewChild<ElementRef<HTMLButtonElement>>('phoneButton');
  private readonly _menu = viewChild<ElementRef<HTMLElement>>('menu');

  constructor() {
    // From a phone's bar to a desktop's (a turned tablet, a wider window): the open menu would belong to neither.
    effect(() => {
      this.phone();
      untracked(() => this.closeMenu(false));
    });
  }

  /** Whether the screen matches a media query, kept current while the bar is there. */
  private _matches(media: string): Signal<boolean> {
    const matches = signal(false);
    const query = globalThis.matchMedia?.(media);
    if (query) {
      const update = () => matches.set(query.matches);
      update();
      query.addEventListener('change', update);
      inject(DestroyRef).onDestroy(() => query.removeEventListener('change', update));
    }
    return matches.asReadonly();
  }

  /** The page is this link's own address. */
  protected isPage(link: BarLink): boolean {
    return this.here() === link.path;
  }

  /** The page is in this link's section: its own address or one under it. */
  protected inSection(link: BarLink): boolean {
    const here = this.here();
    return here === link.path || link.under.some((start) => here.startsWith(start));
  }

  protected toggleMenu(which: 'account' | 'phone'): void {
    if (this.openMenu() === which) {
      this.closeMenu(true);
      return;
    }
    this.openMenu.set(which);
    // Into the menu once it is drawn, as a menu button is expected to do. Drawn
    // means after the next render: with no zone, a microtask runs before it.
    afterNextRender(() => this._menuItems()[0]?.focus(), { injector: this._injector });
  }

  /** Closes the open menu; `returnFocus`: back to the button that opened it (Escape, Tab, the button again). */
  closeMenu(returnFocus: boolean): void {
    const open = this.openMenu();
    if (!open) {
      return;
    }
    this.openMenu.set(null);
    if (returnFocus) {
      (open === 'account' ? this._accountButton() : this._phoneButton())?.nativeElement.focus();
    }
  }

  protected onDocumentClick(event: MouseEvent): void {
    const open = this.openMenu();
    if (!open) {
      return;
    }
    const root = this._host.nativeElement.querySelector(open === 'account' ? '.user-menu' : '.phone-menu');
    if (!root || !root.contains(event.target as Node)) {
      this.closeMenu(false);
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
      // Tab (or Shift+Tab) leaves the menu from its button. Left to the browser,
      // the focus would move to the next item of a menu still drawn - with no
      // zone it is removed only at the next render - and then fall to <body>.
      event.preventDefault();
      this.closeMenu(true);
      return;
    }
    if (next >= 0) {
      event.preventDefault();
      items[next]?.focus();
    }
  }

  /**
   * A language chosen in the bar is the account's too, as the overview's
   * select makes it: the account's language outranks this browser's choice
   * the next time the pages load, and it is the language of the IAM's mails.
   * The page has switched already; when the account refuses, the toast says
   * so and the page stays in the chosen language for this visit.
   *
   * One save at a time, and the last choice is the one saved: two saves in
   * flight together (en, then sk, then en, picked quickly) could be handled
   * in either order and leave the account on a language the page no longer
   * shows. A choice made while a save is on its way waits for it to settle -
   * only the latest such choice, and only if the account does not have it by
   * then.
   */
  protected onLanguageChanged(code: string): void {
    if (this._savingLanguage) {
      this._nextLanguage = code;
      return;
    }
    this._saveLanguage(code);
  }

  /** A language save is on its way. */
  private _savingLanguage = false;
  /** The latest language chosen while it is: saved once it settles. */
  private _nextLanguage: string | null = null;

  private _saveLanguage(code: string): void {
    const me = this.user();
    if (!me || me.language === code) {
      return;
    }
    this._savingLanguage = true;
    /** The save is over: the next choice, if one waits, is saved now - and then this one's refusal is not news. */
    const settle = (): boolean => {
      const next = this._nextLanguage;
      this._savingLanguage = false;
      this._nextLanguage = null;
      if (next !== null) {
        this._saveLanguage(next);
      }
      return next !== null;
    };
    this._accountApi.updateProfile({ language: code }).subscribe({
      next: (user) => {
        this._session.set(user);
        settle();
      },
      error: (error: ApiError) => {
        if (!settle()) {
          this._toast.error(errorText(error, (key) => this._i18n.t(key)));
        }
      },
    });
  }

  /** Signs out of the IAM - nothing is lost: the admin panel's unsaved strings wait in the tab for the same administrator. */
  protected signOut(): void {
    this.signingOut.set(true);
    this._loginApi.logout().subscribe({
      next: () => {
        this.signingOut.set(false);
        this.openMenu.set(null);
        this._session.clear();
        void this._router.navigate(['/logout'], { state: { signedOut: true } });
      },
      error: (error: ApiError) => {
        this.signingOut.set(false);
        this._toast.error(error.status === 0 ? errorText(error, (key) => this._i18n.t(key)) : this._i18n.t('shell.signOutFailed'));
      },
    });
  }

  private _menuItems(): HTMLElement[] {
    const menu = this._menu()?.nativeElement;
    return menu ? Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitem"]')) : [];
  }
}
