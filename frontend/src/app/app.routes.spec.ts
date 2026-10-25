import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, Routes, TitleStrategy, provideRouter, withComponentInputBinding } from '@angular/router';
import { provideAnotokiAdmin } from '@anotoki/lib/admin';
import { provideAnotokiFeedback } from '@anotoki/lib/feedback';
import { provideAnotokiLegal } from '@anotoki/lib/legal';
import { SiteState, SiteStatus, provideAnotokiMigrations } from '@anotoki/lib/migrations';
import { provideAnotokiShell } from '@anotoki/lib/shell';
import { SiteSettings, provideAnotokiSiteSettings } from '@anotoki/lib/site-settings';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { Observable, defer, of, tap } from 'rxjs';
import { AppComponent } from './app.component';
import { routes } from './app.routes';
import { iamAdminToolsConfig } from './shared/admin/admin-tools.config';
import { AccountApi } from './api/account.api';
import { LoginApi } from './api/login.api';
import { AccountUser } from './api/models';
import { SessionService } from './shared/auth/session.service';
import { CHANGELOG_URL, Changelog, ChangelogEntry } from './shared/changelog/changelog.service';
import { iamFeedbackConfig } from './shared/feedback/feedback.config';
import { en } from './shared/i18n/en';
import { IamTitleStrategy } from './shared/i18n/title.strategy';
import { iamTranslationsConfig } from './shared/i18n/translations.config';
import { iamShellConfig, iamUiConfig } from './shared/kit/kit.config';
import { iamLegalConfig } from './shared/legal/legal.config';
import { iamMigrationsConfig } from './shared/migrations/migrations.config';
import { ANNOUNCEMENT_STORAGE_KEY, iamSiteSettingsConfig } from './shared/site-settings/site-settings.config';
import { AuthLayoutComponent } from './sites/main/auth/auth-layout/auth-layout.component';
import { LegalLayoutComponent } from './sites/main/legal/legal-layout/legal-layout.component';
import { AccountLayoutComponent } from './sites/main/account/account-layout.component';
import { AppComponent as AdminAppComponent } from './sites/admin/app.component';

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

/** The IAM's four frames as sites/main/main.routes.ts and the areas' routes give them, each holding a page. */
const FRAMES: Routes = [
  { path: 'account', component: AccountLayoutComponent, children: [{ path: '**', component: PageComponent }] },
  { path: 'admin', component: AdminAppComponent, children: [{ path: '**', component: PageComponent }] },
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

/** The IAM's versions as the start leaves them (shared/changelog): the newest is the footer's version. */
const VERSIONS: ChangelogEntry[] = [{ version: '1.0.0', date: '2026-10-19', changes: { added: ['One anotoki account for every anotoki site'] } }];

/**
 * The frames of the IAM's pages, as app.routes.ts and sites/main/main.routes.ts
 * lay them out: the anotoki family's footer (@anotoki/lib/footer) under the
 * sign-in pages, the account pages, the legal pages and - as in every anotoki
 * admin panel (the owner, 2026-10-18) - the admin panel; and the family's
 * status page, /not-set-up, as a page of its own, with nothing of the IAM
 * round it (the owner's rule of 2026-10-18: no header, no footer). The
 * footer's row is the IAM's since the owner's decision of 2026-10-19: its
 * version and What's new (the newest of public/changelog.json and the IAM's
 * own dialog - neither while there is no list) and Feedback (the family's
 * form), then Terms · Privacy · Contact.
 */
describe("the IAM's frames: the family's footer under every page, and the status page bare", () => {
  const user = signal<AccountUser | null | undefined>(null);
  let generation = 0;
  const session = {
    user,
    known: computed(() => user() !== undefined),
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
        provideAnotokiFeedback(iamFeedbackConfig),
        provideAnotokiAdmin(iamAdminToolsConfig),
        provideAnotokiSiteSettings(iamSiteSettingsConfig),
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
  function footer(): {
    inside: string[];
    row: string[];
    version: string | null;
    versionSaid: string | null;
    whatsNew: string | null;
    feedback: string | null;
    links: string[];
    label: string | null | undefined;
    lang: string | null;
  } {
    const footers = host.querySelectorAll('anotoki-site-footer');
    expect(footers).toHaveLength(1);
    const drawn = footers[0];
    const main = host.querySelector('main')!;
    expect(main.contains(drawn)).toBe(false);
    expect(main.compareDocumentPosition(drawn) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(drawn.nextElementSibling).toBeNull();
    expect(host.querySelectorAll('footer')).toHaveLength(1);
    // The row's parts in its order, the dots between them left out: the version, What's new, Feedback.
    const part = (element: Element) =>
      element.hasAttribute('data-version') ? 'version' : element.hasAttribute('data-whats-new') ? 'whatsNew' : element.hasAttribute('data-open-feedback') ? 'feedback' : element.tagName.toLowerCase();
    return {
      inside: Array.from(drawn.querySelector('footer.anotoki-site-footer__footer')!.children).map((child) => child.tagName.toLowerCase()),
      row: Array.from(drawn.querySelector('.anotoki-site-footer__row')?.children ?? [])
        .filter((child) => !child.classList.contains('anotoki-site-footer__dot'))
        .map(part),
      version: words(drawn.querySelector('[data-version] [aria-hidden="true"]')),
      versionSaid: words(drawn.querySelector('[data-version] .anotoki-visually-hidden')),
      whatsNew: words(drawn.querySelector('[data-whats-new]')),
      feedback: words(drawn.querySelector('[data-open-feedback]')),
      links: Array.from(drawn.querySelectorAll<HTMLAnchorElement>('a.anotoki-legal-links__link')).map((link) => `${words(link)} ${link.getAttribute('href')}`),
      label: drawn.querySelector('nav.anotoki-legal-links__nav')?.getAttribute('aria-label'),
      lang: drawn.querySelector('anotoki-legal-links')!.getAttribute('lang'),
    };
  }

  /** The IAM's row - its version, What's new, Feedback - then Terms · Privacy · Contact; no fan clause. */
  const SLOVAK_FOOTER = {
    inside: ['div', 'anotoki-legal-links'],
    row: ['version', 'whatsNew', 'feedback'],
    version: 'v1.0.0',
    versionSaid: 'Verzia 1.0.0',
    whatsNew: 'Čo je nové',
    feedback: 'Spätná väzba',
    links: ['O projekte /about', 'Podporiť /support', 'Podmienky /terms', 'Súkromie /privacy', 'Kontakt /contact'],
    label: 'Právne informácie',
    lang: null,
  };
  const ENGLISH_FOOTER = {
    inside: ['div', 'anotoki-legal-links'],
    row: ['version', 'whatsNew', 'feedback'],
    version: 'v1.0.0',
    versionSaid: 'Version 1.0.0',
    whatsNew: "What's new",
    feedback: 'Feedback',
    links: ['About /about', 'Support /support', 'Terms /terms', 'Privacy /privacy', 'Contact /contact'],
    label: 'Legal',
    lang: null,
  };

  beforeEach(() => {
    user.set(null);
  });

  afterEach(() => {
    if (fixture) {
      // Neither the footer nor the status page asks the server anything (the status is asked of SiteStatus, faked here) -
      // but What's new, pressed, its list (flushed by its test).
      TestBed.inject(HttpTestingController).verify();
      host.remove();
      fixture = null;
    }
    vi.unstubAllGlobals();
  });

  /** The pages in Slovak, as the database has them; English beside them for the admin panel - and the IAM's versions as the start leaves them. */
  function configureFrames(values: Record<string, string> = {}, versions: ChangelogEntry[] = VERSIONS): void {
    configure(FRAMES, provideTestTranslations({ ...en, ...values }, { language: 'sk', english: en }));
    TestBed.inject(Changelog).entries.set(versions);
  }

  describe('the footer', () => {
    it('under a sign-in page: the family’s, below the form in the sign-in pages’ column - the IAM’s version, What’s new and Feedback, then Terms · Privacy · Contact', async () => {
      configureFrames();
      await at('/login');
      expect(host.querySelector('app-auth-layout')).not.toBeNull();
      expect(footer()).toEqual(SLOVAK_FOOTER);
      expect(Array.from(host.querySelector('.side')!.children).map(named)).toEqual(['div.controls', 'main.main', 'anotoki-site-footer.anotoki-site-footer']);
      expect(host.querySelector('[data-fan]'), 'no fan clause: the IAM is no fan site').toBeNull();
    });

    it('before the list of versions came - or with none to be had: neither the version nor What’s new, and Feedback all the same', async () => {
      configureFrames({}, []);
      await at('/login');
      expect(footer()).toEqual({ ...SLOVAK_FOOTER, row: ['feedback'], version: null, versionSaid: null, whatsNew: null });
    });

    it('What’s new opens the IAM’s own dialog, in the page’s language', async () => {
      // jsdom draws a <dialog> but opens none as a modal: the kit's dialog opens as an open <dialog> here.
      const dialogs = HTMLDialogElement.prototype as Partial<HTMLDialogElement>;
      dialogs.showModal ??= function (this: HTMLDialogElement) {
        this.setAttribute('open', '');
      };
      dialogs.close ??= function (this: HTMLDialogElement) {
        this.removeAttribute('open');
      };
      configureFrames({ 'whatsNew.title': 'Čo je nové', 'whatsNew.version': 'Verzia {version}' });
      await at('/login');
      host.querySelector<HTMLButtonElement>('[data-whats-new]')!.click();
      TestBed.inject(HttpTestingController).expectOne(CHANGELOG_URL).flush(VERSIONS);
      // The dialog's code comes with the first press.
      await vi.waitFor(async () => {
        await settle();
        expect(document.querySelector('anotoki-dialog h3.version')).not.toBeNull();
      });
      const dialog = document.querySelector('anotoki-dialog');
      expect(words(dialog?.querySelector('h2'))).toBe('Čo je nové');
      expect(words(dialog?.querySelector('h3.version'))).toBe('Verzia 1.0.0');
      dialog?.querySelector<HTMLButtonElement>('.anotoki-dialog__footer button')?.click();
      await settle();
      expect(document.querySelector('anotoki-dialog')).toBeNull();
    });

    it('Feedback opens the family’s form in its dialog - for the person signed in, their address written in', async () => {
      user.set({ ...MIRA });
      configureFrames();
      await at('/account/security');
      host.querySelector<HTMLButtonElement>('[data-open-feedback]')!.click();
      // The dialog's and the form's code come with the first press.
      await vi.waitFor(async () => {
        await settle();
        expect(document.querySelector('anotoki-feedback-form [data-message]')).not.toBeNull();
      });
      const form = document.querySelector('anotoki-feedback-form');
      expect(form?.querySelector('[data-anonymous]')).not.toBeNull();
      expect(form?.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe('mira@example.test');
      // The form's buttons are drawn in the dialog's own footer (anotoki-lib 0.8.0), outside the form's element.
      const cancel = document.querySelector<HTMLButtonElement>('[data-feedback-dialog] [data-cancel]');
      expect(cancel).not.toBeNull();
      cancel!.click();
      await settle();
      expect(document.querySelector('anotoki-feedback-form')).toBeNull();
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

    it('in the admin panel too, under its pages in the family’s admin frame - in English, as the panel is', async () => {
      user.set({ ...MIRA });
      configureFrames();
      await at('/admin/users');
      // The new feedback, asked once as an administrator opens the panel, is counted beside Feedback (anotoki-lib 0.8.1).
      TestBed.inject(HttpTestingController).expectOne('/api/admin/feedback/unread').flush({ unread: 3 });
      await settle();
      const frame = host.querySelector('app-admin-root > anotoki-admin-frame')!;
      expect(Array.from(frame.querySelectorAll('.anotoki-side-nav__badge')).map((badge) => [badge.closest('a')?.getAttribute('href'), words(badge)])).toEqual([['/admin/feedback', '3']]);
      // Somebody else signed in, in the same tab (no reload): the panel closes - nothing of the administrator's count is left beside Feedback, and nothing is asked.
      user.set({ ...MIRA, is_admin: false });
      await settle();
      expect(host.querySelector('anotoki-admin-frame, .anotoki-side-nav__badge')).toBeNull();
      // An administrator again: asked afresh (one request - none was made meanwhile).
      user.set({ ...MIRA });
      await settle();
      TestBed.inject(HttpTestingController).expectOne('/api/admin/feedback/unread').flush({ unread: 5 });
      await settle();
      expect(words(host.querySelector('anotoki-admin-frame a[href="/admin/feedback"] .anotoki-side-nav__badge'))).toBe('5');
      // At the foot of the page's column, after the page.
      expect(host.querySelector('.anotoki-admin-frame__page-column')?.lastElementChild?.tagName.toLowerCase()).toBe('anotoki-site-footer');
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });

    it('in the admin panel for somebody who may not use it: under "You don’t have access" as well - no frame round it', async () => {
      user.set({ ...MIRA, is_admin: false });
      configureFrames();
      await at('/admin');
      expect(words(host.querySelector('main.no-access'))).toContain("You don't have access");
      expect(host.querySelector('main.no-access a[href="/account"]')).not.toBeNull();
      expect(host.querySelector('anotoki-admin-frame, anotoki-side-nav, app-top-bar, anotoki-top-bar, anotoki-shell')).toBeNull();
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });

    it('in the admin panel for an administrator whose deletion is pending: told so, the footer under it - no frame round it', async () => {
      user.set({ ...MIRA, is_admin: false, deletion_due_at: '2026-11-20T00:00:00Z' });
      configureFrames();
      await at('/admin/users');
      expect(words(host.querySelector('main.no-access'))).toContain('Your account is being deleted');
      expect(words(host.querySelector('main.no-access a[href="/account"]'))).toBe('Go to your account');
      expect(host.querySelector('anotoki-admin-frame, anotoki-side-nav, app-top-bar, anotoki-top-bar, anotoki-shell')).toBeNull();
      expect(footer()).toEqual(ENGLISH_FOOTER);
    });

    it('says the database’s words: a rewording on the Translations page reaches the footer', async () => {
      configureFrames({ 'anotoki.legalLinks.privacy': 'Ochrana súkromia', 'anotoki.footer.feedback': 'Napíš nám' });
      await at('/login');
      expect(footer().links).toEqual(['O projekte /about', 'Podporiť /support', 'Podmienky /terms', 'Ochrana súkromia /privacy', 'Kontakt /contact']);
      expect(footer().feedback).toBe('Napíš nám');
    });
  });

  /**
   * The administrators' announcement - the family's site settings' one part the IAM offers (anotoki-lib 0.11.0; the
   * owner's decision: no maintenance, no page access on the IAM) -, read as the site starts (app.config.ts): a bar
   * across the top of the sign-in pages' frame, the account pages' and the legal pages', in the page's language, and
   * never in the admin panel.
   */
  describe('the announcement', () => {
    const ANNOUNCEMENT = { level: 'warning', message: { en: 'The sign-in is slow tonight.', sk: 'Prihlasovanie je dnes večer pomalé.' } };

    /** The frames, and the site settings as the start reads them. */
    async function withAnnouncement(answer: object = { announcement: ANNOUNCEMENT }): Promise<void> {
      configureFrames();
      const loaded = TestBed.inject(SiteSettings).load();
      TestBed.inject(HttpTestingController).expectOne('/api/site-settings').flush(answer);
      await loaded;
    }

    /** The frame's own elements, in order. */
    const children = (selector: string) => Array.from(host.querySelector(selector)?.children ?? []).map(named);

    afterEach(() => localStorage.removeItem(ANNOUNCEMENT_STORAGE_KEY));

    it('above a sign-in page, an account page and a legal page - across the top of the frame, in the page’s language', async () => {
      await withAnnouncement();
      await at('/login');
      expect(children('app-auth-layout')).toEqual(['a.skip-link', 'anotoki-announcement.anotoki-announcement', 'div.auth']);
      const bar = host.querySelector('anotoki-announcement [data-announcement]')!;
      expect([bar.getAttribute('data-level'), words(bar.querySelector('.anotoki-announcement__message'))]).toEqual(['warning', 'Prihlasovanie je dnes večer pomalé.']);

      user.set({ ...MIRA });
      await at('/account/security');
      expect(children('app-account-layout')).toEqual(['anotoki-announcement.anotoki-announcement', 'anotoki-shell.anotoki-shell']);
      expect(host.querySelectorAll('[data-announcement]')).toHaveLength(1);

      await at('/terms');
      expect(children('app-legal-layout')).toEqual(['anotoki-announcement.anotoki-announcement', 'anotoki-shell.anotoki-shell']);
      expect(host.querySelectorAll('[data-announcement]')).toHaveLength(1);
    });

    it('never in the admin panel', async () => {
      user.set({ ...MIRA });
      await withAnnouncement();
      await at('/admin/users');
      TestBed.inject(HttpTestingController).expectOne('/api/admin/feedback/unread').flush({ unread: 0 });
      await settle();
      expect(host.querySelector('anotoki-admin-frame')).not.toBeNull();
      expect(host.querySelector('anotoki-announcement, [data-announcement]')).toBeNull();
    });

    it('none to say - nothing written, or switched off: no bar', async () => {
      await withAnnouncement({ announcement: null });
      await at('/login');
      expect(host.querySelector('[data-announcement]')).toBeNull();
    });

    it('Hide: gone, and a mark of it kept under the IAM’s own name - never its words - until they change', async () => {
      await withAnnouncement();
      await at('/login');
      host.querySelector<HTMLButtonElement>('[data-announcement] [data-hide]')!.click();
      await settle();
      expect(host.querySelector('[data-announcement]')).toBeNull();
      const mark = localStorage.getItem(ANNOUNCEMENT_STORAGE_KEY) ?? '';
      expect(mark).toMatch(/^v1:[0-9a-f]{8}$/);
      expect(mark).not.toContain('slow');
    });
  });

  /**
   * The admin panel in the anotoki family's admin frame (`<anotoki-admin-frame>`, anotoki-lib 0.10.0 - the owner's
   * request of 2026-10-24: every admin panel the genshin site's, never mistaken for the site): no top bar, a column of
   * its own, worded and wired by the IAM (sites/admin/app.component).
   */
  describe('the admin panel, in the family’s admin frame', () => {
    /** The frame's pages as its column lists them: each group's heading (none for the first), then its links - "words href", " [page]" for the page's own. */
    const pages = () =>
      Array.from(host.querySelectorAll('anotoki-admin-frame nav.anotoki-side-nav__nav .anotoki-side-nav__group')).map((group) => [
        words(group.querySelector('.anotoki-side-nav__heading')),
        ...Array.from(group.querySelectorAll<HTMLAnchorElement>('a.anotoki-side-nav__link')).map(
          (link) => `${words(link.querySelector('.anotoki-side-nav__label'))} ${link.getAttribute('href')}${link.getAttribute('aria-current') === 'page' ? ' [page]' : ''}`,
        ),
      ]);

    /** An administrator at a page of the panel, read in Slovak elsewhere - the count of new feedback answered: none. */
    async function openPanel(url = '/admin/users'): Promise<Element> {
      user.set({ ...MIRA });
      configureFrames();
      await at(url);
      TestBed.inject(HttpTestingController).expectOne('/api/admin/feedback/unread').flush({ unread: 0 });
      await settle();
      return host.querySelector('app-admin-root > anotoki-admin-frame')!;
    }

    it('no top bar: its column - its name, the person, the way back, Sign out, the theme, then the pages in groups - in English for a reader of Slovak', async () => {
      const frame = await openPanel();
      expect(host.querySelector('app-top-bar, anotoki-top-bar, anotoki-shell')).toBeNull();
      const name = frame.querySelector('.anotoki-admin-frame__head a.anotoki-admin-frame__brand')!;
      expect([words(name), name.getAttribute('href')]).toEqual(['anotoki account · admin panel', '/admin']);
      expect(words(frame.querySelector('.anotoki-admin-frame__name'))).toBe('Mira');
      expect(words(frame.querySelector('.anotoki-admin-frame__email'))).toBe('mira@example.test');
      const back = frame.querySelector('a.anotoki-admin-frame__back')!;
      expect([words(back), back.getAttribute('href')]).toEqual(['Back to your account', '/account']);
      expect(words(frame.querySelector('button.anotoki-admin-frame__sign-out'))).toBe('Sign out');
      // The family's switch, the account's theme - in the IAM's own word for it.
      expect(words(frame.querySelector('.anotoki-admin-frame__theme-label'))).toBe('Appearance');
      expect(frame.querySelector('.anotoki-admin-frame__theme anotoki-theme-toggle [role="radiogroup"]')?.getAttribute('aria-label')).toBe('Appearance');
      expect(words(frame.querySelector('a.anotoki-admin-frame__skip'))).toBe('Skip to content');
      expect(frame.querySelector('nav.anotoki-side-nav__nav')?.getAttribute('aria-label')).toBe('Admin panel');
      // The family's one shape of an admin menu (the owner's request of 2026-10-25): the IAM's own pages at the top with
      // no heading, Localization, then System - the IAM's own system pages, then the family's in the family's order -;
      // the page's own marked.
      expect(pages()).toEqual([
        [
          null,
          'Dashboard /admin',
          'Users /admin/users [page]',
          'Approvals /admin/approvals',
          'Sessions /admin/sessions',
          'Applications /admin/applications',
          'Announcements /admin/announcements',
          'Email log /admin/emails',
        ],
        ['Localization', 'Translations /admin/translations', 'Languages /admin/languages'],
        [
          'System',
          'Settings /admin/settings',
          'Signing keys /admin/keys',
          'Site settings /admin/site-settings',
          'Feedback /admin/feedback',
          'Error log /admin/errors',
          'Audit log /admin/audit',
          'Backups /admin/backups',
          'Migrations /admin/migrations',
        ],
      ]);
      // The page in the frame's <main>, the only one.
      expect(host.querySelectorAll('main')).toHaveLength(1);
      expect(words(frame.querySelector('main#main h1'))).toBe('The page');
    });

    it('Sign out is the account pages’ bar’s own: the IAM ends the session, and the page goes to the signed-out page', async () => {
      const frame = await openPanel();
      frame.querySelector<HTMLButtonElement>('button.anotoki-admin-frame__sign-out')!.click();
      await settle();
      expect(TestBed.inject(LoginApi).logout).toHaveBeenCalledTimes(1);
      expect(user()).toBeNull();
      expect(TestBed.inject(Router).url).toBe('/logout');
    });
  });

  describe('the status page, /not-set-up', () => {
    /** The IAM's own routes and root, in English - the words an IAM that is not set up has (en.ts) - and its versions as the start leaves them. */
    function configureApp(): void {
      configure(routes, provideTestTranslations(en));
      TestBed.inject(Changelog).entries.set(VERSIONS);
    }

    /** Nothing of the IAM round the page: no frame, no bar, no switches, no sky, no footer - one landmark, the page's own. */
    function expectBare(): void {
      for (const selector of [
        'anotoki-shell',
        'anotoki-admin-frame',
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
