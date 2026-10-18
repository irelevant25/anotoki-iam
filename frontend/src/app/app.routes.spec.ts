import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, Routes, TitleStrategy, provideRouter, withComponentInputBinding } from '@angular/router';
import { provideAnotokiLegal } from '@anotoki/lib/legal';
import { SiteState, SiteStatus, provideAnotokiMigrations } from '@anotoki/lib/migrations';
import { provideAnotokiShell } from '@anotoki/lib/shell';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { Observable, defer, of, tap } from 'rxjs';
import { AppComponent } from './app.component';
import { routes } from './app.routes';
import { AccountApi } from './core/api/account.api';
import { LoginApi } from './core/api/login.api';
import { AccountUser } from './core/api/models';
import { SessionService } from './core/auth/session.service';
import { en } from './core/i18n/en';
import { IamTitleStrategy } from './core/i18n/title.strategy';
import { iamTranslationsConfig } from './core/i18n/translations.config';
import { iamShellConfig, iamUiConfig } from './core/kit/kit.config';
import { iamLegalConfig } from './core/legal/legal.config';
import { iamMigrationsConfig } from './core/migrations/migrations.config';
import { AuthLayoutComponent } from './layouts/auth-layout/auth-layout.component';
import { LegalLayoutComponent } from './layouts/legal-layout/legal-layout.component';
import { AccountLayoutComponent } from './pages/account/account-layout.component';
import { AdminLayoutComponent } from './pages/admin/admin-layout.component';

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

/** Whatever page a frame holds: the frames are what is looked at here. */
@Component({ selector: 'app-test-page', changeDetection: ChangeDetectionStrategy.OnPush, template: '<h1>The page</h1>' })
class PageComponent {}

/** The IAM's four frames as app.routes.ts and the areas' routes give them, each holding a page. */
const FRAMES: Routes = [
  { path: 'account', component: AccountLayoutComponent, children: [{ path: '**', component: PageComponent }] },
  { path: 'admin', component: AdminLayoutComponent, children: [{ path: '**', component: PageComponent }] },
  { path: '', component: LegalLayoutComponent, children: [{ path: 'terms', component: PageComponent }] },
  { path: '', component: AuthLayoutComponent, children: [{ path: '**', component: PageComponent }] },
];

/** What the server says of the IAM, one answer each time it is asked - the last one given for every ask after it; ready when none is given. */
interface Answer {
  state: SiteState;
  installed: boolean;
  /** Not given until this settles. */
  held?: Promise<void>;
}

function fakeSiteStatus() {
  const state = signal<SiteState>('unknown');
  const installed = signal(true);
  const answers: Answer[] = [];
  const answer = async (): Promise<SiteState> => {
    const next = (answers.length > 1 ? answers.shift() : answers[0]) ?? { state: 'ready', installed: true };
    await next.held;
    state.set(next.state);
    installed.set(next.installed);
    return next.state;
  };
  return {
    state: state.asReadonly(),
    installed: installed.asReadonly(),
    blocked: computed(() => state() !== 'ready' && state() !== 'unknown'),
    answers,
    check: vi.fn(answer),
    checkForReload: vi.fn(answer),
    report: vi.fn(),
    markReady: vi.fn(),
  };
}

/** An answer the test gives when it chooses. */
function held(answer: Omit<Answer, 'held'>): { answer: Answer; give: () => void } {
  let give = () => undefined as void;
  const settled = new Promise<void>((done) => (give = done));
  return { answer: { ...answer, held: settled }, give };
}

/**
 * The frames of the IAM's pages, as app.routes.ts lays them out: the anotoki
 * family's footer (@anotoki/lib/footer) under the sign-in pages, the account
 * pages, the legal pages and - as in every anotoki admin panel (the owner,
 * 2026-10-18) - the admin panel; and the family's status page, /not-set-up,
 * as a page of its own, with nothing of the IAM round it (the owner's rule of
 * 2026-10-18: no header, no footer). The IAM has no version, no What's new and
 * no feedback form: its footer is the top border and Terms · Privacy ·
 * Contact alone.
 */
describe("the IAM's frames: the family's footer under every page, and the status page bare", () => {
  const user = signal<AccountUser | null>(null);
  let generation = 0;
  const session = {
    user,
    signedIn: computed(() => !!user()),
    isAdmin: computed(() => !!user()?.is_admin),
    ensure: () => of(user()),
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
  let status: ReturnType<typeof fakeSiteStatus>;
  let fixture: ComponentFixture<AppComponent> | null = null;
  let host: HTMLElement;

  const words = (element: Element | null | undefined) => element?.textContent?.replace(/\s+/g, ' ').trim() ?? null;
  /** An element as tag.firstClass, as the kit's markup names it. */
  const named = (element: Element) => element.tagName.toLowerCase() + (element.className ? '.' + String(element.className).split(' ')[0] : '');

  /** The app as app.config.ts makes it - its root, the given routes, the family's modules as the IAM wires them - with no server. */
  function configure(appRoutes: Routes, translations: ReturnType<typeof provideTestTranslations>): void {
    status = fakeSiteStatus();
    vi.stubGlobal('matchMedia', (query: string) => ({ matches: false, media: query, addEventListener: () => undefined, removeEventListener: () => undefined }));
    TestBed.configureTestingModule({
      providers: [
        // As app.config.ts has it: the query's `next` arrives as the not-set-up page's input.
        provideRouter(appRoutes, withComponentInputBinding()),
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: TitleStrategy, useClass: IamTitleStrategy },
        { provide: SessionService, useValue: session },
        { provide: LoginApi, useValue: { logout: vi.fn(() => of({})) } },
        { provide: AccountApi, useValue: { updateProfile: vi.fn(() => of(user())) } },
        provideAnotokiTranslations(iamTranslationsConfig),
        translations,
        provideAnotokiUi(iamUiConfig),
        provideAnotokiShell(iamShellConfig),
        provideAnotokiMigrations(iamMigrationsConfig),
        { provide: SiteStatus, useValue: status },
        provideAnotokiLegal(iamLegalConfig),
      ],
    });
    fixture = TestBed.createComponent(AppComponent);
    host = fixture.nativeElement;
    document.body.appendChild(host);
  }

  /** Lets the lazy pages, the resolvers, the answers and change detection run out. */
  async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture?.whenStable();
    }
  }

  async function at(url: string): Promise<void> {
    await TestBed.inject(Router).navigateByUrl(url);
    await settle();
  }

  /** The footer as drawn - the family's, after the page and last in its frame, a landmark of its own - and what it holds. */
  function footer(): { inside: string[]; links: string[]; label: string | null | undefined; lang: string | null } {
    const footers = host.querySelectorAll('anotoki-site-footer');
    expect(footers).toHaveLength(1);
    const drawn = footers[0];
    const main = host.querySelector('main')!;
    expect(main.contains(drawn)).toBe(false);
    expect(main.compareDocumentPosition(drawn) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(drawn.nextElementSibling).toBeNull();
    expect(host.querySelectorAll('footer')).toHaveLength(1);
    return {
      inside: Array.from(drawn.querySelector('footer.anotoki-site-footer__footer')!.children).map((part) => part.tagName.toLowerCase()),
      links: Array.from(drawn.querySelectorAll<HTMLAnchorElement>('a.anotoki-legal-links__link')).map((link) => `${words(link)} ${link.getAttribute('href')}`),
      label: drawn.querySelector('nav.anotoki-legal-links__nav')?.getAttribute('aria-label'),
      lang: drawn.querySelector('anotoki-legal-links')!.getAttribute('lang'),
    };
  }

  /** Terms · Privacy · Contact and nothing else: no version, no What's new, no Feedback, no fan clause - the IAM has none of them. */
  const SLOVAK_FOOTER = { inside: ['anotoki-legal-links'], links: ['Podmienky /terms', 'Súkromie /privacy', 'Kontakt /contact'], label: 'Právne informácie', lang: null };
  const ENGLISH_FOOTER = { inside: ['anotoki-legal-links'], links: ['Terms /terms', 'Privacy /privacy', 'Contact /contact'], label: 'Legal', lang: null };

  beforeEach(() => {
    user.set(null);
  });

  afterEach(() => {
    if (fixture) {
      // Neither the footer nor the status page asks the server anything (the status is asked of SiteStatus, faked here).
      TestBed.inject(HttpTestingController).verify();
      host.remove();
      fixture = null;
    }
    vi.unstubAllGlobals();
  });

  describe('the footer', () => {
    /** The pages in Slovak, as the database has them; English beside them for the admin panel. */
    function configureFrames(values: Record<string, string> = {}): void {
      configure(FRAMES, provideTestTranslations({ ...en, ...values }, { language: 'sk', english: en }));
    }

    it('under a sign-in page: the family’s, below the form in the sign-in pages’ column - Terms · Privacy · Contact alone, no row above them', async () => {
      configureFrames();
      await at('/login');
      expect(host.querySelector('app-auth-layout')).not.toBeNull();
      expect(footer()).toEqual(SLOVAK_FOOTER);
      expect(Array.from(host.querySelector('.side')!.children).map(named)).toEqual(['div.controls', 'main.main', 'anotoki-site-footer.anotoki-site-footer']);
      expect(host.querySelector('.anotoki-site-footer__row, [data-version], [data-whats-new], [data-open-feedback], [data-fan]')).toBeNull();
    });

    it('under an account page: in the family’s frame, after the page', async () => {
      user.set({ ...MIRA });
      configureFrames();
      await at('/account/security');
      const shell = host.querySelector('app-account-layout anotoki-shell')!;
      expect(Array.from(shell.children).map(named)).toEqual(['a.anotoki-shell__skip-link', 'app-top-bar', 'div.anotoki-shell__frame', 'anotoki-site-footer.anotoki-site-footer']);
      expect(footer()).toEqual(SLOVAK_FOOTER);
    });

    it('under a legal page: the same footer, the page it is on marked', async () => {
      configureFrames();
      await at('/terms');
      expect(host.querySelector('app-legal-layout anotoki-shell > anotoki-site-footer')).not.toBeNull();
      expect(footer()).toEqual(SLOVAK_FOOTER);
      expect(host.querySelector('a[data-legal-link="terms"]')?.getAttribute('aria-current')).toBe('page');
    });

    it('in the admin panel too, under its pages and its side navigation - in English, as the panel is', async () => {
      user.set({ ...MIRA });
      configureFrames();
      await at('/admin/users');
      const shell = host.querySelector('app-admin-layout anotoki-shell')!;
      expect(shell.querySelector('nav.anotoki-shell__sidenav')).not.toBeNull();
      expect(shell.lastElementChild?.tagName.toLowerCase()).toBe('anotoki-site-footer');
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });

    it('in the admin panel for somebody who may not use it: under "You don’t have access" as well', async () => {
      user.set({ ...MIRA, is_admin: false });
      configureFrames();
      await at('/admin');
      expect(words(host.querySelector('.no-access'))).toContain("You don't have access");
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });

    it('says the database’s words: a rewording on the Translations page reaches the footer', async () => {
      configureFrames({ 'anotoki.legalLinks.privacy': 'Ochrana súkromia' });
      await at('/login');
      expect(footer().links).toEqual(['Podmienky /terms', 'Ochrana súkromia /privacy', 'Kontakt /contact']);
    });
  });

  describe('the status page, /not-set-up', () => {
    /** The IAM's own routes and root, in English - the words an IAM that is not set up has (en.ts). */
    function configureApp(): void {
      configure(routes, provideTestTranslations(en));
    }

    /** Nothing of the IAM round the page: no frame, no bar, no switches, no sky, no footer - one landmark, the page's own. */
    function expectBare(): void {
      for (const selector of [
        'anotoki-shell',
        'app-top-bar',
        'anotoki-top-bar',
        'nav',
        'app-auth-layout',
        'app-legal-layout',
        '.sky',
        'anotoki-language-switcher',
        'anotoki-theme-toggle',
        'anotoki-site-footer',
        'anotoki-legal-links',
        'footer',
        'header',
        'a[href="#main"]',
      ]) {
        expect(host.querySelector(selector), selector).toBeNull();
      }
      expect(host.querySelectorAll('main')).toHaveLength(1);
      expect(host.querySelector('app-not-set-up > main')).not.toBeNull();
    }

    it('is a route of its own, on no frame: no layout of app.routes.ts holds it', () => {
      const own = routes.filter((route) => route.path === 'not-set-up');
      expect(own).toHaveLength(1);
      expect(own[0].children ?? own[0].loadChildren ?? null).toBeNull();
      for (const route of routes) {
        expect((route.children ?? []).map((child) => child.path)).not.toContain('not-set-up');
      }
    });

    it('never installed: the family’s status page in a main of its own - the installer and the developer’s line - and nothing of the IAM round it', async () => {
      configureApp();
      status.answers.push({ state: 'not-set-up', installed: false });
      await at('/not-set-up?next=%2Fterms');
      const main = host.querySelector('app-not-set-up > main');
      expect(words(main?.querySelector('anotoki-site-status h1'))).toBe(en['notSetUp.title']);
      expect(words(main?.querySelector('anotoki-site-status a'))).toBe(en['notSetUp.open']);
      expect(words(main?.querySelector('.developer'))).toContain('php -S localhost:8100 router.php');
      expectBare();
      expect(document.title).toBe('Not set up yet · anotoki account');
    });

    for (const [closed, answer] of [
      ['installed once, and set up no more', { state: 'not-set-up', installed: true }],
      ['its database out of reach', { state: 'unavailable', installed: true }],
    ] as const) {
      it(`${closed}: the page that waits, never the installer - as bare`, async () => {
        configureApp();
        status.answers.push(answer);
        await at('/not-set-up?next=%2Faccount');
        expect(words(host.querySelector('app-not-set-up > main anotoki-site-status h1'))).toBe('The site is not available right now');
        expect(host.querySelector('anotoki-site-status a, .developer')).toBeNull();
        expectBare();
      });
    }

    it('found going on as the page opens (an update waits, which closes nothing): a moment’s spinner, as bare - then back to the page it came from', async () => {
      configureApp();
      const second = held({ state: 'update-pending', installed: true });
      // The title asks first; the page asks again, and the test holds that answer.
      status.answers.push({ state: 'update-pending', installed: true }, second.answer);
      await at('/not-set-up?next=%2Faccount%2Fsecurity');
      expect(host.querySelector('app-not-set-up > main anotoki-spinner')).not.toBeNull();
      expect(host.querySelector('anotoki-site-status')).toBeNull();
      expectBare();

      // From scratch, so the whole app asks again: `location`, never the router. (jsdom has no navigation: the call is what is seen.)
      const assign = vi.fn();
      vi.stubGlobal('location', { origin: window.location.origin, assign });
      second.give();
      await settle();
      expect(assign).toHaveBeenCalledWith('/account/security');
    });

    it('once the IAM is set up, the browser goes back to the page it came from - which has its frame and the footer again', async () => {
      configureApp();
      const second = held({ state: 'ready', installed: true });
      status.answers.push({ state: 'not-set-up', installed: false }, second.answer);
      await at('/not-set-up?next=%2Fterms');
      expect(host.querySelector('anotoki-site-status')).not.toBeNull();
      expectBare();

      const assign = vi.fn();
      vi.stubGlobal('location', { origin: window.location.origin, assign });
      second.give();
      await settle();
      expect(assign).toHaveBeenCalledWith('/terms');

      // The page it came from, as that load draws it: in its frame, with the footer under it.
      await at('/terms');
      expect(host.querySelector('app-legal-layout anotoki-shell > app-top-bar')).not.toBeNull();
      expect(host.querySelector('anotoki-site-status')).toBeNull();
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });
  });
});
