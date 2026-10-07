import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { Subject, of, throwError } from 'rxjs';
import { AccountApi } from '../../core/api/account.api';
import { ApiError } from '../../core/api/api-error';
import { LoginApi } from '../../core/api/login.api';
import { AccountUser, SiteLanguage } from '../../core/api/models';
import { SessionService } from '../../core/auth/session.service';
import { TranslationKey, en } from '../../core/i18n/en';
import { errorText } from '../../core/i18n/error-text';
import { TranslationService } from '../../core/i18n/translation.service';
import { ToastService } from '../../ui/toast/toast.service';
import { PHONE_BAR_MAX, TopBarComponent } from './top-bar.component';

/** The bar's words in Slovak, as migrations 006 and 008 write them. */
const SLOVAK: Partial<Record<TranslationKey, string>> = {
  'brand.area': 'účet',
  'brand.home': 'anotoki účet - domov',
  'shell.mainNav': 'Hlavná navigácia',
  'shell.userMenu': 'Ponuka účtu',
  'shell.admin': 'Administrátorský panel',
  'shell.signOut': 'Odhlásiť sa',
  'shell.signOutFailed': 'Odhlásenie nefungovalo. Skús to znova.',
  'shell.menu': 'Ponuka',
  'shell.roleAdmin': 'Administrátor',
  'account.nav.overview': 'Prehľad',
  'account.nav.security': 'Zabezpečenie',
  'account.nav.sessions': 'Prihlásenia',
  'account.nav.applications': 'Aplikácie',
  'account.nav.tokens': 'Tokeny pre skripty',
  'common.signIn': 'Prihlásiť sa',
  'lang.button': 'Jazyk: {name} ({code})',
};

const LANGUAGES: SiteLanguage[] = [
  { code: 'en', name: 'English', native_name: 'English' },
  { code: 'sk', name: 'Slovak', native_name: 'Slovenčina' },
];

/**
 * The translation service as the pages see it: the language on the page, the
 * languages on offer, the words - English in the admin panel, whatever is
 * chosen (`effectiveLanguage`), and the key itself for a key with no string.
 */
class FakeTranslations {
  readonly language = signal('sk');
  readonly languages = signal(LANGUAGES);
  readonly englishOnly = signal(false);
  readonly effectiveLanguage = computed(() => (this.englishOnly() ? 'en' : this.language()));
  readonly choose = vi.fn(async (code: string) => {
    this.language.set(code);
    return true;
  });

  t(key: TranslationKey, params?: Record<string, string | number | null | undefined>): string {
    const text = this.effectiveLanguage() === 'sk' ? (SLOVAK[key] ?? key) : en[key];
    return text.replace(/\{(\w+)\}/g, (whole, name: string) => (params?.[name] === undefined || params[name] === null ? whole : String(params[name])));
  }
}

const MIRA: AccountUser = {
  id: 7,
  username: 'Mira',
  email: 'mira@example.test',
  email_confirmed: true,
  language: 'sk',
  created_at: '2026-01-01T00:00:00Z',
  has_password: true,
  password_login_enabled: true,
  google_connected: false,
  google_email: null,
  totp_enabled: false,
  recovery_codes_remaining: 0,
  trusted_devices: 0,
  force_password_change: false,
  is_admin: true,
  news_mail: true,
  preferences: null,
  deletion_due_at: null,
};

/** Whatever page the router draws: the bar only needs it to be somewhere. */
@Component({ selector: 'app-test-page', changeDetection: ChangeDetectionStrategy.OnPush, template: '' })
class PageComponent {}

describe('TopBarComponent', () => {
  const user = signal<AccountUser | null>(null);
  const session = {
    user,
    signedIn: computed(() => !!user()),
    isAdmin: computed(() => !!user()?.is_admin),
    set: (next: AccountUser) => user.set(next),
    clear: () => user.set(null),
  };
  const loginApi = { logout: vi.fn() };
  const accountApi = { updateProfile: vi.fn() };
  let i18n: FakeTranslations;

  let fixture: ComponentFixture<TopBarComponent>;
  let host: HTMLElement;

  const userButton = () => host.querySelector<HTMLButtonElement>('.user-button')!;
  const phoneButton = () => host.querySelector<HTMLButtonElement>('.menu-button')!;
  const items = () => Array.from(host.querySelectorAll<HTMLElement>('[role="menu"] [role="menuitem"]'));
  const words = (element: Element | null) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const parts = (selector: string) => Array.from(host.querySelectorAll(selector)).map(words);
  const links = () => Array.from(host.querySelectorAll<HTMLAnchorElement>('.topbar-nav a'));
  /** A link as "words href", with " [page]" for aria-current and " [here]" for the section the page is in. */
  const described = (link: HTMLAnchorElement) =>
    `${words(link)} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}${link.classList.contains('is-active') ? ' [here]' : ''}`;
  /** What stands in an element, in order: each child as tag.firstClass. */
  const childrenOf = (selector: string) =>
    Array.from(host.querySelector(selector)!.children).map((child) => child.tagName.toLowerCase() + (child.className ? '.' + String(child.className).split(' ')[0] : ''));

  async function draw(area: 'account' | 'admin' = 'account'): Promise<void> {
    fixture = TestBed.createComponent(TopBarComponent);
    fixture.componentRef.setInput('area', area);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await fixture.whenStable();
  }

  async function at(url: string): Promise<void> {
    await TestBed.inject(Router).navigateByUrl(url);
    await fixture.whenStable();
  }

  async function openMenu(): Promise<void> {
    (host.querySelector<HTMLButtonElement>('.user-button') ?? phoneButton()).click();
    await fixture.whenStable();
  }

  /** Whether a window `width` wide matches a (max-width: …) query. */
  const fits = (width: number, query: string) => width <= Number(/max-width:\s*(\d+)px/.exec(query)?.[1] ?? Infinity);
  /** The media queries handed out, with the listeners they were given - told by `resizeTo`. */
  let queries: { query: string; list: { matches: boolean }; listeners: Set<() => void> }[] = [];

  /** A window `width` wide, as the bar's media queries see it. */
  function windowOf(width: number): void {
    queries = [];
    vi.stubGlobal('matchMedia', (query: string) => {
      const listeners = new Set<() => void>();
      const list = {
        matches: fits(width, query),
        addEventListener: (_type: string, listener: () => void) => listeners.add(listener),
        removeEventListener: (_type: string, listener: () => void) => listeners.delete(listener),
      };
      queries.push({ query, list, listeners });
      return list;
    });
  }

  /** The window becomes `width` wide: every query whose answer changes tells its listeners, as a browser does. */
  async function resizeTo(width: number): Promise<void> {
    for (const { query, list, listeners } of queries) {
      if (list.matches !== fits(width, query)) {
        list.matches = fits(width, query);
        listeners.forEach((listener) => listener());
      }
    }
    await fixture.whenStable();
  }

  beforeEach(() => {
    user.set({ ...MIRA });
    i18n = new FakeTranslations();
    loginApi.logout.mockReset().mockReturnValue(of({}));
    accountApi.updateProfile.mockReset().mockImplementation((body: { language: string }) => of({ ...user()!, language: body.language }));
    windowOf(1280);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '**', component: PageComponent }]),
        { provide: TranslationService, useValue: i18n },
        { provide: SessionService, useValue: session },
        { provide: LoginApi, useValue: loginApi },
        { provide: AccountApi, useValue: accountApi },
      ],
    });
  });

  afterEach(() => {
    host?.remove();
    vi.unstubAllGlobals();
  });

  it('is one row in the family’s order: the brand, the links, then the language, the theme and the person', async () => {
    await draw();
    expect(childrenOf('.topbar-inner')).toEqual(['app-brand', 'nav.topbar-nav', 'div.topbar-actions']);
    expect(childrenOf('.topbar-actions')).toEqual(['app-language-switcher.is-compact', 'app-theme-toggle', 'div.user-menu']);
    expect(host.querySelector('.topbar-nav')?.getAttribute('aria-label')).toBe('Hlavná navigácia');
    expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'účet']);
    expect(host.querySelector('app-brand a')?.getAttribute('aria-label')).toBe('anotoki účet - domov');
    // The language is the bar's 40 px button, saying the language it shows - and, in its name, the code on it (WCAG 2.5.3).
    expect(host.querySelector('app-language-switcher .switcher')?.getAttribute('aria-label')).toBe('Jazyk: Slovenčina (SK)');
    expect(words(host.querySelector('app-language-switcher .switcher'))).toBe('SK');
  });

  it('links the account pages, and marks the one the page is on', async () => {
    await draw();
    await at('/account');
    expect(links().map(described)).toEqual([
      'Prehľad /account [page] [here]',
      'Zabezpečenie /account/security',
      'Prihlásenia /account/sessions',
      'Aplikácie /account/applications',
      'Tokeny pre skripty /account/tokens',
    ]);
    await at('/account/tokens?created=1');
    expect(links().map(described).at(-1)).toBe('Tokeny pre skripty /account/tokens [page] [here]');
    expect(links().map(described)[0]).toBe('Prehľad /account');
  });

  it('the person’s menu: name, address, role; the admin panel for an administrator; signing out - no "Account", the pages are the links', async () => {
    await draw();
    expect(userButton().getAttribute('aria-label')).toBe('Ponuka účtu: Mira');
    await openMenu();
    expect(host.querySelector('#user-menu')?.getAttribute('aria-label')).toBe('Ponuka účtu');
    expect(parts('#user-menu .menu-head > span')).toEqual(['Mira', 'mira@example.test', 'Administrátor']);
    expect(items().map(words)).toEqual(['Administrátorský panel', 'Odhlásiť sa']);
    expect(items()[0].getAttribute('href')).toBe('/admin');

    user.set({ ...MIRA, is_admin: false });
    await fixture.whenStable();
    expect(host.querySelector('.menu-role')).toBeNull();
    expect(items().map(words)).toEqual(['Odhlásiť sa']);
  });

  it('moves focus into the menu when it opens, arrow keys and End between its items, back to its button on Escape', async () => {
    await draw();
    expect(items()).toEqual([]);
    await openMenu();
    expect(userButton().getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(items()[0]);

    items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[1]);
    items()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[0]);
    items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    expect(document.activeElement).toBe(items().at(-1));

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(document.activeElement).toBe(userButton());
  });

  /**
   * Tab (and Shift+Tab) from the first, a middle and the last item of the open
   * menu: the menu closes, the browser's own move is stopped (it would land on
   * the next item of a menu about to go, then on <body>), and the focus is on
   * the button that opened it - where the next Tab moves on from.
   */
  async function expectTabOut(button: () => HTMLButtonElement, middle: number): Promise<void> {
    const count = items().length;
    for (const [which, shift] of [
      [0, false],
      [middle, false],
      [count - 1, false],
      [middle, true],
    ] as const) {
      if (items().length === 0) {
        button().click();
        await fixture.whenStable();
      }
      expect(items().length).toBe(count);
      items()[which].focus();
      const tab = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: shift, bubbles: true, cancelable: true });
      items()[which].dispatchEvent(tab);
      expect(tab.defaultPrevented).toBe(true);
      expect(document.activeElement).toBe(button());
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(button().getAttribute('aria-expanded')).toBe('false');
      expect(document.activeElement).toBe(button());
    }
  }

  it('closes on Tab and Shift+Tab with the focus on its button, from any item - never on <body>', async () => {
    // The desktop menu has two items ("Back to your account", "Sign out"): the first and the last. The phone's
    // seven are where a middle one is (below).
    i18n.englishOnly.set(true);
    await draw('admin');
    await openMenu();
    expect(items().length).toBe(2);
    await expectTabOut(userButton, 1);
    // And in the account area, an administrator's: the admin panel, signing out.
    host.remove();
    i18n.englishOnly.set(false);
    await draw();
    await openMenu();
    expect(items().length).toBe(2);
    await expectTabOut(userButton, 1);
  });

  it('closes on a click outside it - a click inside it is no reason to', async () => {
    await draw();
    await openMenu();
    host.querySelector<HTMLElement>('.menu-head')!.click();
    await fixture.whenStable();
    expect(items().length).toBe(2);

    document.body.click();
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(userButton().getAttribute('aria-expanded')).toBe('false');
  });

  it('in the admin panel: English whatever is chosen, no links and no language, the way back to the account', async () => {
    i18n.englishOnly.set(true);
    await draw('admin');
    expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'admin panel']);
    expect(host.querySelector('app-brand a')?.getAttribute('href')).toBe('/admin');
    expect(host.querySelector('app-brand a')?.getAttribute('aria-label')).toBe('anotoki admin panel - home');
    expect(host.querySelector('.topbar-nav')).toBeNull();
    expect(host.querySelector('app-language-switcher')).toBeNull();
    expect(userButton().getAttribute('aria-label')).toBe('Account menu: Mira');

    await openMenu();
    expect(parts('#user-menu .menu-head > span')).toEqual(['Mira', 'mira@example.test', 'Administrator']);
    expect(items().map(words)).toEqual(['Back to your account', 'Sign out']);
    expect(items()[0].getAttribute('href')).toBe('/account');
  });

  it('follows a change of language without being drawn again', async () => {
    await draw();
    i18n.language.set('en');
    await fixture.whenStable();
    expect(userButton().getAttribute('aria-label')).toBe('Account menu: Mira');
    expect(links().map(words)).toEqual(['Overview', 'Security', 'Sessions', 'Applications', 'Tokens for scripts']);
    expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'account']);
  });

  describe('the language, chosen in the bar', () => {
    async function choose(code: string): Promise<void> {
      host.querySelector<HTMLButtonElement>('app-language-switcher .switcher')!.click();
      await fixture.whenStable();
      host.querySelector<HTMLButtonElement>(`app-language-switcher [role="menuitemradio"][lang="${code}"]`)!.click();
      await fixture.whenStable();
    }

    it('switches the page, and saves it to the account as the overview’s select does', async () => {
      await draw();
      await choose('en');
      expect(i18n.choose).toHaveBeenCalledWith('en');
      expect(accountApi.updateProfile).toHaveBeenCalledWith({ language: 'en' });
      expect(user()?.language).toBe('en');
      expect(words(host.querySelector('app-language-switcher .switcher'))).toBe('EN');
      // The menu closed, the focus back on its button.
      expect(host.querySelector('app-language-switcher [role="menu"]')).toBeNull();
      expect(document.activeElement).toBe(host.querySelector('app-language-switcher .switcher'));
    });

    it('asks nothing of the account for the language already on the page', async () => {
      await draw();
      await choose('sk');
      expect(i18n.choose).not.toHaveBeenCalled();
      expect(accountApi.updateProfile).not.toHaveBeenCalled();
    });

    it('keeps the choice on the page, and says so, when the account refuses it - the account keeps its language', async () => {
      const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
      accountApi.updateProfile.mockReturnValue(throwError(() => refused));
      await draw();
      const toast = vi.spyOn(TestBed.inject(ToastService), 'error');
      await choose('en');
      expect(accountApi.updateProfile).toHaveBeenCalledWith({ language: 'en' });
      expect(toast).toHaveBeenCalledTimes(1);
      expect(toast).toHaveBeenCalledWith(errorText(refused, (key) => i18n.t(key)));
      expect(user()?.language).toBe('sk');
      expect(i18n.language()).toBe('en');
      expect(words(host.querySelector('app-language-switcher .switcher'))).toBe('EN');
    });

    it('signed out: switches the page and saves nothing - there is no account to save it to', async () => {
      user.set(null);
      await draw();
      await choose('en');
      expect(i18n.choose).toHaveBeenCalledWith('en');
      expect(words(host.querySelector('app-language-switcher .switcher'))).toBe('EN');
      expect(accountApi.updateProfile).not.toHaveBeenCalled();
    });

    /** Saves held until the test answers them, in the order it likes. */
    function heldSaves(): Subject<AccountUser>[] {
      const saves: Subject<AccountUser>[] = [];
      accountApi.updateProfile.mockImplementation(() => {
        const save = new Subject<AccountUser>();
        saves.push(save);
        return save;
      });
      return saves;
    }

    function answer(save: Subject<AccountUser>, language: string): void {
      save.next({ ...MIRA, language });
      save.complete();
    }

    it('two quick choices: one save at a time, so the earlier cannot be answered last - the account ends on the page’s language', async () => {
      const saves = heldSaves();
      await draw();
      await choose('en');
      await choose('sk');
      // The page is on the second choice; the first save is on its way, the second waits for it.
      expect(i18n.language()).toBe('sk');
      expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: 'en' }]]);
      // The second save cannot be answered before the first: it has not been asked. The first answers...
      answer(saves[0], 'en');
      expect(user()?.language).toBe('en');
      // ...and only then is the latest choice saved, so its answer is the last word.
      expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: 'en' }], [{ language: 'sk' }]]);
      answer(saves[1], 'sk');
      expect(user()?.language).toBe('sk');
      expect(saves.length).toBe(2);
    });

    it('three quick choices: only the latest waiting one is saved - and not at all when the account has it by then', async () => {
      const saves = heldSaves();
      await draw();
      await choose('en');
      await choose('sk');
      await choose('en');
      expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
      answer(saves[0], 'en');
      // The latest choice is English, which the account now has: nothing more to save.
      expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
      expect(user()?.language).toBe('en');
      expect(i18n.language()).toBe('en');
    });

    it('a refused save a later choice has overtaken is not said: the later choice is what counts', async () => {
      const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
      const saves = heldSaves();
      await draw();
      const toast = vi.spyOn(TestBed.inject(ToastService), 'error');
      await choose('en');
      await choose('sk');
      saves[0].error(refused);
      expect(toast).not.toHaveBeenCalled();
      // The account kept Slovak, the latest choice: nothing more to save.
      expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
      expect(user()?.language).toBe('sk');
    });

    it('closes its menu on Tab with the focus on its button', async () => {
      await draw();
      const button = host.querySelector<HTMLButtonElement>('app-language-switcher .switcher')!;
      button.click();
      await fixture.whenStable();
      const options = Array.from(host.querySelectorAll<HTMLElement>('app-language-switcher [role="menuitemradio"]'));
      // Into the menu on the language in use.
      expect(document.activeElement).toBe(options[1]);
      const tab = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
      options[1].dispatchEvent(tab);
      expect(tab.defaultPrevented).toBe(true);
      await fixture.whenStable();
      expect(host.querySelector('app-language-switcher [role="menu"]')).toBeNull();
      expect(document.activeElement).toBe(button);
    });
  });

  it('signs out: the IAM ends the session, and the page goes to the signed-out page', async () => {
    await draw();
    await openMenu();
    items()
      .find((item) => words(item) === 'Odhlásiť sa')!
      .click();
    await fixture.whenStable();
    expect(loginApi.logout).toHaveBeenCalledTimes(1);
    expect(user()).toBeNull();
    expect(TestBed.inject(Router).url).toBe('/logout');
  });

  it('stays signed in, and says so, when signing out does not work', async () => {
    const failed: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
    loginApi.logout.mockReturnValue(throwError(() => failed));
    await draw();
    const toast = vi.spyOn(TestBed.inject(ToastService), 'error');
    await openMenu();
    items()
      .find((item) => words(item) === 'Odhlásiť sa')!
      .click();
    await fixture.whenStable();
    expect(user()?.username).toBe('Mira');
    expect(toast).toHaveBeenCalledWith('Odhlásenie nefungovalo. Skús to znova.');
    expect(TestBed.inject(Router).url).not.toBe('/logout');
  });

  it('signed out: a link to sign in, coming back to the page', async () => {
    user.set(null);
    await draw();
    await at('/account/security');
    const signIn = host.querySelector<HTMLAnchorElement>('.topbar-actions a.btn-primary, .topbar-actions a[appButton]')!;
    expect(words(signIn)).toBe('Prihlásiť sa');
    expect(signIn.getAttribute('href')).toBe('/login?next=%2Faccount%2Fsecurity');
  });

  describe('on a phone', () => {
    it('is the brand (the mark and "účet"), the language, the theme and a menu button - nothing else in the row', async () => {
      windowOf(390);
      await draw();
      expect(parts('app-brand .wordmark > span')).toEqual(['účet']);
      expect(host.querySelector('.topbar-nav')).toBeNull();
      expect(host.querySelector('.user-button')).toBeNull();
      expect(childrenOf('.topbar-actions')).toEqual(['app-language-switcher.is-compact', 'app-theme-toggle', 'div.phone-menu']);
      expect(phoneButton().getAttribute('aria-label')).toBe('Ponuka');
      expect(phoneButton().getAttribute('aria-haspopup')).toBe('menu');
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');
    });

    it('holds the account pages and the person’s menu: focus in, arrow keys, Home, Escape back to its button', async () => {
      windowOf(360);
      await draw();
      await at('/account/sessions');
      await openMenu();
      expect(phoneButton().getAttribute('aria-expanded')).toBe('true');
      expect(host.querySelector('#topbar-menu')?.getAttribute('aria-label')).toBe('Ponuka');
      expect(items().map(words)).toEqual(['Prehľad', 'Zabezpečenie', 'Prihlásenia', 'Aplikácie', 'Tokeny pre skripty', 'Administrátorský panel', 'Odhlásiť sa']);
      expect(items()[2].getAttribute('aria-current')).toBe('page');
      expect(parts('#topbar-menu .menu-head > span')).toEqual(['Mira', 'mira@example.test', 'Administrátor']);
      expect(document.activeElement).toBe(items()[0]);

      items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
      expect(document.activeElement).toBe(items().at(-1));
      items()
        .at(-1)!
        .dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
      expect(document.activeElement).toBe(items()[0]);

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(document.activeElement).toBe(phoneButton());
    });

    it('closes when a link is chosen, on a click outside, and on Tab out of it', async () => {
      windowOf(390);
      await draw();
      await openMenu();
      items()[1].click();
      await fixture.whenStable();
      expect(items()).toEqual([]);
      expect(TestBed.inject(Router).url).toBe('/account/security');

      await openMenu();
      document.body.click();
      await fixture.whenStable();
      expect(items()).toEqual([]);

      // Tab from the first, a middle and the last item (and Shift+Tab): closed, the focus on the menu button.
      await openMenu();
      await expectTabOut(phoneButton, 3);
    });

    it('in the admin panel: the person’s menu alone, in English', async () => {
      i18n.englishOnly.set(true);
      windowOf(390);
      await draw('admin');
      expect(parts('app-brand .wordmark > span')).toEqual(['admin panel']);
      await openMenu();
      expect(items().map(words)).toEqual(['Back to your account', 'Sign out']);
      expect(host.querySelectorAll('#topbar-menu .menu-sep').length).toBe(1);
    });

    it('signed out: Sign in is in the menu, after the pages', async () => {
      user.set(null);
      windowOf(360);
      await draw();
      await openMenu();
      expect(items().map(words)).toEqual(['Prehľad', 'Zabezpečenie', 'Prihlásenia', 'Aplikácie', 'Tokeny pre skripty', 'Prihlásiť sa']);
      expect(items().at(-1)?.getAttribute('href')).toMatch(/^\/login\?next=/);
    });

    it(`is a desktop’s bar from ${PHONE_BAR_MAX + 1} px: the links and the person in the row again`, async () => {
      windowOf(PHONE_BAR_MAX + 1);
      await draw();
      expect(host.querySelector('.menu-button')).toBeNull();
      expect(links().length).toBe(5);
      expect(userButton()).not.toBeNull();
      expect(parts('app-brand .wordmark > span')).toEqual(['anotoki', 'účet']);
    });

    it(`closes an open menu when the window crosses ${PHONE_BAR_MAX} px, either way`, async () => {
      windowOf(390);
      await draw();
      await openMenu();
      expect(items().length).toBe(7);

      // A turned tablet, a wider window: the phone's menu belongs to neither bar -
      // and is not open still when the window comes back.
      await resizeTo(PHONE_BAR_MAX + 1);
      expect(host.querySelector('.menu-button')).toBeNull();
      expect(links().length).toBe(5);
      expect(items()).toEqual([]);
      expect(userButton().getAttribute('aria-expanded')).toBe('false');
      await resizeTo(390);
      expect(items()).toEqual([]);
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');

      // The other way: the person's menu, open on a desktop, closes as the bar folds.
      await resizeTo(1280);
      await openMenu();
      expect(items().length).toBe(2);
      await resizeTo(PHONE_BAR_MAX);
      expect(host.querySelector('.user-button')).toBeNull();
      expect(items()).toEqual([]);
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');
      await resizeTo(1280);
      expect(items()).toEqual([]);
      expect(userButton().getAttribute('aria-expanded')).toBe('false');
    });
  });
});
