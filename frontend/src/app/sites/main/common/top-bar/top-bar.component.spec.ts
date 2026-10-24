import { ChangeDetectionStrategy, Component, Injectable, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideAnotokiShell } from '@anotoki/lib/shell';
import { TranslationService as LibraryTranslations, TranslationBundle, provideAnotokiTranslations } from '@anotoki/lib/translations';
import { TEST_LANGUAGES } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { ToastService } from '@anotoki/lib/ui/toast';
import { Observable, Subject, defer, of, tap, throwError } from 'rxjs';
import { AccountApi } from '../../../../api/account.api';
import { ApiError } from '../../../../api/api-error';
import { LoginApi } from '../../../../api/login.api';
import { AccountUser } from '../../../../api/models';
import { SessionService } from '../../../../shared/auth/session.service';
import { en } from '../../../../shared/i18n/en';
import { iamTranslationsConfig } from '../../../../shared/i18n/translations.config';
import { iamShellConfig, iamUiConfig } from '../../../../shared/kit/kit.config';
import { AppTopBarComponent, PHONE_BAR_MAX } from './top-bar.component';

/** The bar's words in Slovak, as migrations 006, 008 and 017 leave them (017 moved `lang.*` under the library's keys). */
const SLOVAK: Record<string, string> = {
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
  'anotoki.language.label': 'Jazyk',
  'anotoki.language.button': 'Jazyk: {name} ({code})',
  'theme.label': 'Vzhľad',
  'theme.light': 'Svetlý',
  'theme.dark': 'Tmavý',
  'theme.auto': 'Systémový',
  'theme.autoHint': 'Podľa nastavenia systému',
  'error.generic': 'Niečo sa pokazilo. Skús to znova.',
};

/** The two bundles the server answers: English (the IAM's English, en.ts) and Slovak over it, English beside it. */
const ENGLISH: TranslationBundle = { language: 'en', languages: TEST_LANGUAGES, values: { ...en } };
const SLOVAK_BUNDLE: TranslationBundle = { language: 'sk', languages: TEST_LANGUAGES, values: { ...en, ...SLOVAK }, english: { ...en } };

/**
 * The family's TranslationService, the real one, with no HTTP: the page is in
 * Slovak, and a language chosen is answered at once with its bundle - so what
 * a choice does (the page first, then the account, one save at a time) is the
 * library's own, wired as the IAM wires it (shared/i18n/translations.config.ts).
 */
@Injectable()
class TwoLanguages extends LibraryTranslations {
  constructor() {
    super();
    this.use(SLOVAK_BUNDLE);
  }

  /** The strings as an administrator left them on the Translations page: Slovak with some of them reworded. */
  reword(values: Record<string, string>): void {
    this.use({ ...SLOVAK_BUNDLE, values: { ...SLOVAK_BUNDLE.values, ...values } });
  }

  protected override fetchBundle(code: string): Promise<unknown> {
    return Promise.resolve(code === 'sk' ? SLOVAK_BUNDLE : ENGLISH);
  }

  protected override canAsk(): boolean {
    return true;
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

/**
 * The IAM's top bar: the family's `<anotoki-top-bar>` (@anotoki/lib/shell,
 * whose own specs hold its menus, keys and fold) as the IAM words and wires
 * it - drawn here for real, so that what the IAM promises is checked on the
 * bar people get: its order, its links and menu, its words from the IAM's
 * strings, the language saved to the account one save at a time (the
 * family's translations, as the IAM configures them), signing in and out,
 * PHONE_BAR_MAX, and Tab out of every menu onto its button.
 */
describe('AppTopBarComponent', () => {
  const user = signal<AccountUser | null>(null);
  /** Moves on with set() and clear(), as SessionService's does (its rule is checked with the real one in shared/auth/session.service.spec.ts). */
  let generation = 0;
  const session = {
    user,
    signedIn: computed(() => !!user()),
    isAdmin: computed(() => !!user()?.is_admin),
    set: (next: AccountUser) => {
      generation++;
      user.set(next);
    },
    setFrom: (request: Observable<AccountUser>) =>
      defer(() => {
        const sent = generation;
        return request.pipe(
          tap((next) => {
            if (sent === generation) {
              user.set(next);
            }
          }),
        );
      }),
    clear: () => {
      generation++;
      user.set(null);
    },
  };
  const loginApi = { logout: vi.fn() };
  const accountApi = { updateProfile: vi.fn() };
  const i18n = () => TestBed.inject(LibraryTranslations) as TwoLanguages;

  let fixture: ComponentFixture<AppTopBarComponent>;
  let host: HTMLElement;

  const words = (element: Element | null) => element?.textContent?.replace(/\s+/g, ' ').trim();
  const parts = (selector: string) => Array.from(host.querySelectorAll(selector)).map(words);
  const personButton = () => host.querySelector<HTMLButtonElement>('.anotoki-top-bar__person-button')!;
  const phoneButton = () => host.querySelector<HTMLButtonElement>('.anotoki-top-bar__menu-button')!;
  const languageButton = () => host.querySelector<HTMLButtonElement>('anotoki-language-switcher .anotoki-language-switcher__switcher')!;
  const languageNote = () => host.querySelector('anotoki-language-switcher .anotoki-language-switcher__note');
  /** The bar's own open menu (the person's or the phone's) - not the language switcher's. */
  const menu = () => host.querySelector<HTMLElement>('.anotoki-top-bar__actions > anotoki-menu [role="menu"]');
  const items = () => Array.from(menu()?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
  const links = () => Array.from(host.querySelectorAll<HTMLAnchorElement>('nav.anotoki-top-bar__nav a'));
  const wordmark = () => parts('anotoki-brand .anotoki-brand__wordmark > span');
  const menuHead = () => parts('.anotoki-top-bar__actions > anotoki-menu .anotoki-top-bar__menu-head > span');
  /** A link as "words href", with " [page]" for aria-current and " [here]" for the section the page is in. */
  const described = (link: HTMLAnchorElement) =>
    `${words(link)} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}${link.classList.contains('anotoki-top-bar__link--active') ? ' [here]' : ''}`;
  /** What stands in an element, in order: each child as tag.firstClass. */
  const childrenOf = (selector: string) =>
    Array.from(host.querySelector(selector)!.children).map((child) => child.tagName.toLowerCase() + (child.className ? '.' + String(child.className).split(' ')[0] : ''));

  async function draw(area: 'account' | 'public' = 'account'): Promise<void> {
    // Where the page is, as the title strategy tells the translations after every navigation: a translated area.
    i18n().noteAddress('/account');
    fixture = TestBed.createComponent(AppTopBarComponent);
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
    (host.querySelector<HTMLButtonElement>('.anotoki-top-bar__person-button') ?? phoneButton()).click();
    await fixture.whenStable();
  }

  /** Everything a choice or a save's answer sets going has happened - the words, the session, the save it lets go - and the bar is drawn. */
  async function settled(): Promise<void> {
    await new Promise((done) => setTimeout(done));
    await fixture.whenStable();
  }

  /** Whether a window `width` wide matches a query: only (max-width: …) ones match anything here. */
  const fits = (width: number, query: string) => {
    const most = /max-width:\s*(\d+)px/.exec(query);
    return most ? width <= Number(most[1]) : false;
  };
  /** The media queries handed out, with the listeners they were given - told by `resizeTo`. */
  let queries: { query: string; list: { matches: boolean }; listeners: Set<() => void> }[] = [];

  /** A window `width` wide, as the bar's media queries see it. */
  function windowOf(width: number): void {
    queries = [];
    vi.stubGlobal('matchMedia', (query: string) => {
      const listeners = new Set<() => void>();
      const list = {
        matches: fits(width, query),
        media: query,
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
    loginApi.logout.mockReset().mockReturnValue(of({}));
    accountApi.updateProfile.mockReset().mockImplementation((body: { language: string }) => of({ ...user()!, language: body.language }));
    windowOf(1280);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: '**', component: PageComponent }]),
        { provide: SessionService, useValue: session },
        { provide: LoginApi, useValue: loginApi },
        { provide: AccountApi, useValue: accountApi },
        provideAnotokiTranslations(iamTranslationsConfig),
        { provide: LibraryTranslations, useClass: TwoLanguages },
        provideAnotokiUi(iamUiConfig),
        provideAnotokiShell(iamShellConfig),
      ],
    });
  });

  afterEach(() => {
    host?.remove();
    vi.unstubAllGlobals();
  });

  it('is one row in the family’s order: the brand, the links, then the language, the theme and the person', async () => {
    await draw();
    expect(childrenOf('.anotoki-top-bar__row')).toEqual(['anotoki-brand.anotoki-brand', 'nav.anotoki-top-bar__nav', 'div.anotoki-top-bar__actions']);
    expect(childrenOf('.anotoki-top-bar__actions')).toEqual([
      'anotoki-language-switcher.anotoki-language-switcher',
      'anotoki-theme-toggle.anotoki-theme-toggle',
      'button.anotoki-top-bar__person-button',
      'anotoki-menu.anotoki-menu',
    ]);
    expect(host.querySelector('nav.anotoki-top-bar__nav')?.getAttribute('aria-label')).toBe('Hlavná navigácia');
    expect(wordmark()).toEqual(['anotoki', 'účet']);
    expect(host.querySelector('anotoki-brand a')?.getAttribute('aria-label')).toBe('anotoki účet - domov');
    expect(host.querySelector('anotoki-brand a')?.getAttribute('href')).toBe('/account');
    // The language is the bar's 40 px button, saying the language it shows - and, in its name, the code on it (WCAG 2.5.3).
    expect(languageButton().getAttribute('aria-label')).toBe('Jazyk: Slovenčina (SK)');
    expect(words(languageButton())).toBe('SK');
    // The theme, in the IAM's own words.
    expect(host.querySelector('anotoki-theme-toggle [role="radiogroup"]')?.getAttribute('aria-label')).toBe('Vzhľad');
  });

  it('says the language in the database’s words: a rewording of the library’s keys on the Translations page reaches the bar', async () => {
    await draw();
    i18n().reword({ 'anotoki.language.button': 'Reč stránok: {name} ({code})' });
    await fixture.whenStable();
    expect(languageButton().getAttribute('aria-label')).toBe('Reč stránok: Slovenčina (SK)');
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

  it('the person’s menu: name, address, role; the admin panel for an administrator; signing out - no "Your anotoki account", the pages are the links', async () => {
    await draw();
    expect(personButton().getAttribute('aria-label')).toBe('Ponuka účtu: Mira');
    await openMenu();
    expect(menu()?.getAttribute('aria-label')).toBe('Ponuka účtu');
    expect(menuHead()).toEqual(['Mira', 'mira@example.test', 'Administrátor']);
    expect(items().map(words)).toEqual(['Administrátorský panel', 'Odhlásiť sa']);
    expect(items()[0].getAttribute('href')).toBe('/admin');
    // The family's "Your anotoki account" is this site: never an item of its own menu.
    expect(words(menu())).not.toMatch(/Tvoj anotoki účet|Your anotoki account/i);

    // No longer an administrator: no role, and no way to the panel.
    user.set({ ...MIRA, is_admin: false });
    await fixture.whenStable();
    expect(host.querySelector('.anotoki-top-bar__menu-role')).toBeNull();
    expect(items().map(words)).toEqual(['Odhlásiť sa']);

    // Somebody else signed in (another tab): the menu open for the one before closes.
    user.set({ ...MIRA, username: 'Tomas', email: 'tomas@example.test' });
    await fixture.whenStable();
    expect(menu()).toBeNull();
    expect(personButton().getAttribute('aria-label')).toBe('Ponuka účtu: Tomas');
  });

  it('moves focus into the menu when it opens, arrow keys and End between its items, back to its button on Escape', async () => {
    await draw();
    expect(items()).toEqual([]);
    await openMenu();
    expect(personButton().getAttribute('aria-expanded')).toBe('true');
    expect(document.activeElement).toBe(items()[0]);

    items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[1]);
    items()[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
    expect(document.activeElement).toBe(items()[0]);
    items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'End', bubbles: true }));
    expect(document.activeElement).toBe(items().at(-1));

    items()
      .at(-1)!
      .dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(document.activeElement).toBe(personButton());
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
    // The desktop menu has two items for an administrator (the admin panel, Sign out): the first and the last. The
    // phone's seven are where a middle one is (below).
    await draw();
    await openMenu();
    expect(items().length).toBe(2);
    await expectTabOut(personButton, 1);
  });

  it('closes on a click outside it - a click inside it is no reason to', async () => {
    await draw();
    await openMenu();
    host.querySelector<HTMLElement>('.anotoki-top-bar__menu-head')!.click();
    await fixture.whenStable();
    expect(items().length).toBe(2);

    document.body.click();
    await fixture.whenStable();
    expect(items()).toEqual([]);
    expect(personButton().getAttribute('aria-expanded')).toBe('false');
  });

  it('follows a change of language without being drawn again', async () => {
    await draw();
    await i18n().setLanguage('en', { remember: false });
    await fixture.whenStable();
    expect(personButton().getAttribute('aria-label')).toBe('Account menu: Mira');
    expect(links().map(words)).toEqual(['Overview', 'Security', 'Sessions', 'Applications', 'Tokens for scripts']);
    expect(wordmark()).toEqual(['anotoki', 'account']);
  });

  describe('the language, chosen in the bar', () => {
    async function choose(code: string): Promise<void> {
      languageButton().click();
      await fixture.whenStable();
      host.querySelector<HTMLButtonElement>(`anotoki-language-switcher [role="menuitemradio"][lang="${code}"]`)!.click();
      await settled();
    }

    it('switches the page, and saves it to the account as the overview’s select does', async () => {
      await draw();
      await choose('en');
      expect(i18n().language()).toBe('en');
      expect(accountApi.updateProfile).toHaveBeenCalledWith({ language: 'en' });
      expect(user()?.language).toBe('en');
      expect(words(languageButton())).toBe('EN');
      expect(languageButton().getAttribute('aria-label')).toBe('Language: English (EN)');
      expect(languageNote()).toBeNull();
      // The menu closed, the focus back on its button.
      expect(host.querySelector('anotoki-language-switcher [role="menu"]')).toBeNull();
      expect(document.activeElement).toBe(languageButton());
    });

    it('asks nothing of the account for the language already on the page', async () => {
      await draw();
      await choose('sk');
      expect(accountApi.updateProfile).not.toHaveBeenCalled();
    });

    it('keeps the choice on the page, and says so under the button, when the account refuses it - the account keeps its language', async () => {
      const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
      accountApi.updateProfile.mockReturnValue(throwError(() => refused));
      await draw();
      const toast = vi.spyOn(TestBed.inject(ToastService), 'error');
      await choose('en');
      expect(accountApi.updateProfile).toHaveBeenCalledWith({ language: 'en' });
      expect(user()?.language).toBe('sk');
      expect(i18n().language()).toBe('en');
      expect(words(languageButton())).toBe('EN');
      // The library's words for it (`anotoki.language.notSaved`), in the language now on the page.
      expect(words(languageNote())).toBe('The language could not be saved to your anotoki account - it holds for this visit only.');
      expect(languageNote()?.getAttribute('role')).toBe('alert');
      expect(toast).not.toHaveBeenCalled();
      // The next choice is the answer to the note.
      accountApi.updateProfile.mockImplementation((body: { language: string }) => of({ ...user()!, language: body.language }));
      await choose('sk');
      expect(languageNote()).toBeNull();
    });

    it('signed out: switches the page and saves nothing - there is no account to save it to', async () => {
      user.set(null);
      await draw();
      await choose('en');
      expect(i18n().language()).toBe('en');
      expect(words(languageButton())).toBe('EN');
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

    async function answer(save: Subject<AccountUser>, language: string): Promise<void> {
      save.next({ ...MIRA, language });
      save.complete();
      await settled();
    }

    it('two quick choices: one save at a time, so the earlier cannot be answered last - the account ends on the page’s language', async () => {
      const saves = heldSaves();
      await draw();
      await choose('en');
      await choose('sk');
      // The page is on the second choice; the first save is on its way, the second waits for it.
      expect(i18n().language()).toBe('sk');
      expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: 'en' }]]);
      // The second save cannot be answered before the first: it has not been asked. The first answers...
      await answer(saves[0], 'en');
      expect(user()?.language).toBe('en');
      // ...and only then is the latest choice saved, so its answer is the last word.
      expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: 'en' }], [{ language: 'sk' }]]);
      await answer(saves[1], 'sk');
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
      await answer(saves[0], 'en');
      // The latest choice is English, which the account now has: nothing more to save.
      expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
      expect(user()?.language).toBe('en');
      expect(i18n().language()).toBe('en');
    });

    it('a refused save a later choice has overtaken is not said: the later choice is what counts', async () => {
      const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
      const saves = heldSaves();
      await draw();
      await choose('en');
      await choose('sk');
      saves[0].error(refused);
      await settled();
      expect(languageNote()).toBeNull();
      // The account kept Slovak, the latest choice: nothing more to save.
      expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
      expect(user()?.language).toBe('sk');
    });

    it('a save answered after Sign out signs nobody back in', async () => {
      const saves = heldSaves();
      await draw();
      await choose('en');
      expect(accountApi.updateProfile.mock.calls).toEqual([[{ language: 'en' }]]);
      // Sign out while the save is on its way; then it is answered, with the account as saved.
      await openMenu();
      items()
        .find((item) => words(item) === 'Sign out')!
        .click();
      await settled();
      expect(user()).toBeNull();
      await answer(saves[0], 'en');
      expect(user()).toBeNull();
      expect(host.querySelector('.anotoki-top-bar__person-button')).toBeNull();
    });

    it('closes its menu on Tab with the focus on its button', async () => {
      await draw();
      languageButton().click();
      await fixture.whenStable();
      const options = Array.from(host.querySelectorAll<HTMLElement>('anotoki-language-switcher [role="menuitemradio"]'));
      // Into the menu on the language in use.
      expect(document.activeElement).toBe(options[1]);
      const tab = new KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true });
      options[1].dispatchEvent(tab);
      expect(tab.defaultPrevented).toBe(true);
      await fixture.whenStable();
      expect(host.querySelector('anotoki-language-switcher [role="menu"]')).toBeNull();
      expect(document.activeElement).toBe(languageButton());
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

  it('signed out: Sign in, coming back to the page', async () => {
    user.set(null);
    await draw();
    await at('/account/security?tab=1');
    const signIn = host.querySelector<HTMLButtonElement>('.anotoki-top-bar__actions .anotoki-top-bar__sign-in')!;
    expect(words(signIn)).toBe('Prihlásiť sa');
    signIn.click();
    await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/login?next=%2Faccount%2Fsecurity');
  });

  describe('on a page anybody may read - the legal pages (area "public")', () => {
    it('signed in: the account pages’ bar - the brand, their links (none of them the page), the language, the person’s menu with the admin panel', async () => {
      await draw('public');
      await at('/privacy');
      expect(childrenOf('.anotoki-top-bar__row')).toEqual(['anotoki-brand.anotoki-brand', 'nav.anotoki-top-bar__nav', 'div.anotoki-top-bar__actions']);
      expect(wordmark()).toEqual(['anotoki', 'účet']);
      expect(host.querySelector('anotoki-brand a')?.getAttribute('href')).toBe('/account');
      expect(links().map(described)).toEqual([
        'Prehľad /account',
        'Zabezpečenie /account/security',
        'Prihlásenia /account/sessions',
        'Aplikácie /account/applications',
        'Tokeny pre skripty /account/tokens',
      ]);
      expect(languageButton().getAttribute('aria-label')).toBe('Jazyk: Slovenčina (SK)');
      await openMenu();
      expect(items().map(words)).toEqual(['Administrátorský panel', 'Odhlásiť sa']);
    });

    it('signed out: no links - each would be the sign-in page -, the language, the theme, and Sign in coming back to the page', async () => {
      user.set(null);
      await draw('public');
      await at('/privacy');
      expect(links()).toEqual([]);
      expect(childrenOf('.anotoki-top-bar__actions').slice(0, 2)).toEqual(['anotoki-language-switcher.anotoki-language-switcher', 'anotoki-theme-toggle.anotoki-theme-toggle']);
      const signIn = host.querySelector<HTMLButtonElement>('.anotoki-top-bar__actions .anotoki-top-bar__sign-in')!;
      expect(words(signIn)).toBe('Prihlásiť sa');
      signIn.click();
      await fixture.whenStable();
      expect(TestBed.inject(Router).url).toBe('/login?next=%2Fprivacy');
    });

    it('the links come with a sign-in and go with Sign out, the page staying where it is', async () => {
      user.set(null);
      await draw('public');
      await at('/terms');
      expect(links()).toEqual([]);
      session.set({ ...MIRA });
      await fixture.whenStable();
      expect(links().length).toBe(5);
      session.clear();
      await fixture.whenStable();
      expect(links()).toEqual([]);
      expect(TestBed.inject(Router).url).toBe('/terms');
    });

    it('on a phone, signed out: the menu holds Sign in alone', async () => {
      user.set(null);
      windowOf(360);
      await draw('public');
      await openMenu();
      expect(items().map(words)).toEqual(['Prihlásiť sa']);
    });
  });

  it('the person’s name gives way up to 1240 px - the round button alone', async () => {
    windowOf(1240);
    await draw();
    expect(host.querySelector('header')?.classList).toContain('anotoki-top-bar__topbar--nameless');
    host.remove();
    windowOf(1241);
    await draw();
    expect(host.querySelector('header')?.classList).not.toContain('anotoki-top-bar__topbar--nameless');
  });

  describe('on a phone', () => {
    it('is the brand (the mark and "účet"), the language, the theme and a menu button - nothing else in the row', async () => {
      windowOf(390);
      await draw();
      expect(wordmark()).toEqual(['účet']);
      expect(host.querySelector('nav.anotoki-top-bar__nav')).toBeNull();
      expect(host.querySelector('.anotoki-top-bar__person-button')).toBeNull();
      expect(childrenOf('.anotoki-top-bar__actions')).toEqual([
        'anotoki-language-switcher.anotoki-language-switcher',
        'anotoki-theme-toggle.anotoki-theme-toggle',
        'button.anotoki-top-bar__round',
        'anotoki-menu.anotoki-menu',
      ]);
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
      expect(menu()?.getAttribute('aria-label')).toBe('Ponuka');
      expect(items().map(words)).toEqual(['Prehľad', 'Zabezpečenie', 'Prihlásenia', 'Aplikácie', 'Tokeny pre skripty', 'Administrátorský panel', 'Odhlásiť sa']);
      expect(items()[2].getAttribute('aria-current')).toBe('page');
      expect(menuHead()).toEqual(['Mira', 'mira@example.test', 'Administrátor']);
      expect(document.activeElement).toBe(items()[0]);

      items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true }));
      expect(document.activeElement).toBe(items().at(-1));
      items()
        .at(-1)!
        .dispatchEvent(new KeyboardEvent('keydown', { key: 'Home', bubbles: true }));
      expect(document.activeElement).toBe(items()[0]);

      items()[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
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

    it('signed out: Sign in is in the menu, after the pages', async () => {
      user.set(null);
      windowOf(360);
      await draw();
      await openMenu();
      expect(items().map(words)).toEqual(['Prehľad', 'Zabezpečenie', 'Prihlásenia', 'Aplikácie', 'Tokeny pre skripty', 'Prihlásiť sa']);
      items().at(-1)!.click();
      await fixture.whenStable();
      expect(TestBed.inject(Router).url).toMatch(/^\/login\?next=/);
    });

    it(`is a phone’s bar up to ${PHONE_BAR_MAX} px and a desktop’s from ${PHONE_BAR_MAX + 1}: the links and the person in the row again`, async () => {
      windowOf(PHONE_BAR_MAX);
      await draw();
      expect(phoneButton()).not.toBeNull();
      expect(links().length).toBe(0);
      host.remove();
      windowOf(PHONE_BAR_MAX + 1);
      await draw();
      expect(host.querySelector('.anotoki-top-bar__menu-button')).toBeNull();
      expect(links().length).toBe(5);
      expect(personButton()).not.toBeNull();
      expect(wordmark()).toEqual(['anotoki', 'účet']);
    });

    it(`closes an open menu when the window crosses ${PHONE_BAR_MAX} px, either way`, async () => {
      windowOf(390);
      await draw();
      await openMenu();
      expect(items().length).toBe(7);

      // A turned tablet, a wider window: the phone's menu belongs to neither bar -
      // and is not open still when the window comes back.
      await resizeTo(PHONE_BAR_MAX + 1);
      expect(host.querySelector('.anotoki-top-bar__menu-button')).toBeNull();
      expect(links().length).toBe(5);
      expect(items()).toEqual([]);
      expect(personButton().getAttribute('aria-expanded')).toBe('false');
      await resizeTo(390);
      expect(items()).toEqual([]);
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');

      // The other way: the person's menu, open on a desktop, closes as the bar folds.
      await resizeTo(1280);
      await openMenu();
      expect(items().length).toBe(2);
      await resizeTo(PHONE_BAR_MAX);
      expect(host.querySelector('.anotoki-top-bar__person-button')).toBeNull();
      expect(items()).toEqual([]);
      expect(phoneButton().getAttribute('aria-expanded')).toBe('false');
      await resizeTo(1280);
      expect(items()).toEqual([]);
      expect(personButton().getAttribute('aria-expanded')).toBe('false');
    });
  });
});
