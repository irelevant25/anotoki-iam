import { ChangeDetectionStrategy, Component, Type } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { ANOTOKI_LEGAL_CONFIG, provideAnotokiLegal } from '@anotoki/lib/legal';
import { ContactPageComponent, PrivacyPageComponent, TermsPageComponent } from '@anotoki/lib/legal/pages';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { en } from '../i18n/en';
import { TranslationService } from '../i18n/translation.service';
import { iamUiConfig } from '../kit/kit.config';
import { ACCOUNT_DELETION_PATH, iamLegalFacts, iamLegalPagesConfig } from './legal-facts';
import { iamLegalConfig } from './legal.config';

/** Every address a legal page of the IAM's may link to: its own pages, the family's address and operator, the authority, the processors. */
const ADDRESSES = [
  '/account/security',
  '/contact',
  'mailto:support@anotoki.eu',
  'https://codehero.sk',
  'https://dataprotection.gov.sk/',
  'https://www.websupport.sk',
  'https://www.cloudflare.com/privacypolicy/',
  'https://policies.google.com/privacy',
];

/** The legal pages' frame, as layouts/legal-layout gives the pages their configuration: the IAM's, with its facts. */
@Component({
  selector: 'app-test-privacy-frame',
  imports: [PrivacyPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: iamLegalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-privacy-page />',
})
class PrivacyFrameComponent {}

@Component({
  selector: 'app-test-contact-frame',
  imports: [ContactPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: iamLegalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-contact-page />',
})
class ContactFrameComponent {}

@Component({
  selector: 'app-test-terms-frame',
  imports: [TermsPageComponent],
  providers: [{ provide: ANOTOKI_LEGAL_CONFIG, useFactory: iamLegalPagesConfig }],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '<anotoki-terms-page />',
})
class TermsFrameComponent {}

/**
 * The family's legal pages (@anotoki/lib/legal/pages) as the IAM fills them in:
 * the root configuration (core/legal/legal.config.ts - the language, the
 * database's words, no facts) and the facts the pages' frame gives them
 * (core/legal/legal-facts.ts) - drawn for real, so that what the Privacy page
 * every anotoki site links to says is checked on the page people read: the
 * account's own parts, its cookies by name, who else handles data, and every
 * link where the code says, whatever an administrator rewords.
 */
describe('the legal pages, as the IAM fills them in', () => {
  let fixture: ComponentFixture<unknown> | null = null;

  function configure(values: Record<string, string> = {}): void {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideTestTranslations({ ...en, ...values }), provideAnotokiUi(iamUiConfig), provideAnotokiLegal(iamLegalConfig)],
    });
  }

  async function draw(frame: Type<unknown>): Promise<HTMLElement> {
    fixture = TestBed.createComponent(frame);
    document.body.appendChild(fixture.nativeElement);
    await fixture.whenStable();
    return fixture.nativeElement as HTMLElement;
  }

  const texts = (host: HTMLElement, selector: string) => Array.from(host.querySelectorAll(selector)).map((element) => element.textContent?.replace(/\s+/g, ' ').trim());

  afterEach(() => {
    fixture?.destroy();
    fixture = null;
    document.body.innerHTML = '';
  });

  it('the root configuration: the page’s language, the database’s words of the library’s keys - and no facts, which come with the pages’ frame', () => {
    configure({ 'anotoki.legal.privacyTitle': 'Our privacy' });
    const config = TestBed.inject(ANOTOKI_LEGAL_CONFIG);
    expect(config.language?.()).toBe('en');
    expect(config.translate?.('anotoki.legal.privacyTitle')).toBe('Our privacy');
    expect(config.paths).toBeUndefined();
    expect(config.operator).toBeUndefined();
    expect(() => config.facts()).toThrow(/frame/);
  });

  it('a library word the IAM says in its own: Terms’ first heading is the IAM’s key - "About the anotoki account", not "What anotoki account is" - reworded as any of its strings', async () => {
    configure();
    const config = TestBed.inject(ANOTOKI_LEGAL_CONFIG);
    expect(config.translate?.('anotoki.legal.termsWhatTitle')).toBe('About the anotoki account');
    // Every other library word is the library's, as the database has it.
    expect(config.translate?.('anotoki.legal.termsTitle')).toBeNull();

    const host = await draw(TermsFrameComponent);
    expect(host.querySelector('[data-section="what"] h2')?.textContent?.trim()).toBe('About the anotoki account');
    expect(texts(host, '.anotoki-legal-page__toc-link')[0]).toBe('About the anotoki account');
    expect(host.querySelector('[data-about]')?.textContent?.trim()).toBe(en['legal.about']);
  });

  it('the heading follows a rewording of the IAM’s key on the Translations page', async () => {
    configure({ 'legal.termsWhatTitle': 'All about your anotoki account' });
    const host = await draw(TermsFrameComponent);
    expect(host.querySelector('[data-section="what"] h2')?.textContent?.trim()).toBe('All about your anotoki account');
  });

  it('the facts: the account itself in twelve parts, the feedback form in the library’s words, its audit log and encrypted backups in its own, no visitors, its cookies by name', () => {
    configure();
    const i18n = TestBed.inject(TranslationService);
    const facts = iamLegalFacts(i18n, true);
    expect(facts.site).toBe('anotoki account');
    expect(facts.about).toBe(en['legal.about']);
    expect(facts.account).toEqual({ isIam: true, deleteUrl: ACCOUNT_DELETION_PATH, deletionDays: 30 });
    expect(facts.data.map((part) => part.name)).toEqual([
      'Your account',
      'Two-factor authentication',
      'Signing in with Google',
      'Where you are signed in',
      'Sign-in attempts',
      'Remembered devices',
      'Browsers you sign in from',
      'The anotoki sites',
      'E-mails to you',
      'News from anotoki sites',
      'Approval of new accounts',
      'Deleting your account',
    ]);
    // The family's feedback form (the owner's decision of 2026-10-19): the library's words and keeping - iamFeedback() changes none.
    expect(facts.feedback).toBe(true);
    expect(facts.visitors).toBeUndefined();
    expect(facts.errorLog).toBeUndefined();
    expect(facts.auditLog).toEqual({ what: en['legal.audit.what'], why: en['legal.audit.why'], kept: en['legal.audit.kept'] });
    expect(facts.backups).toEqual({ encrypted: true, what: en['legal.backups.what'], why: en['legal.backups.why'], kept: en['legal.backups.kept'] });
    expect(facts.storage.map((item) => item.name)).toEqual([
      'anotoki_signed_in',
      '__Host-anotoki_iam',
      'anotoki_iam_device',
      'anotoki-iam:device-token',
      'anotoki-iam:theme',
      'anotoki-iam:lang, anotoki-iam:lang-cache',
      'anotoki-iam:lang',
      'anotoki-iam:localization-drafts',
    ]);
    // On a plain-http development machine the session cookie goes by its bare name (api/session.php).
    expect(iamLegalFacts(i18n, false).storage[1].name).toBe('anotoki_iam');
    const webHost = { name: 'WebSupport s.r.o.', url: 'https://www.websupport.sk' };
    expect(facts.processors).toEqual([
      { preset: 'hosting', ...webHost },
      { preset: 'cloudflare' },
      { preset: 'google', role: en['legal.google.role'] },
      { preset: 'mail', ...webHost, role: en['legal.mail.role'] },
    ]);
    // An address is the code's: no string of the facts holds one (the 0.6.0 review; the backend's test of 020 too).
    for (const [key, text] of Object.entries(en).filter(([key]) => key.startsWith('legal.'))) {
      expect([key, /https?:|:\/\/|www\.|@/.test(text)]).toEqual([key, false]);
    }
  });

  it('the Privacy page, drawn for real in the frame’s configuration: the IAM’s parts and cookies, who else handles data, and every link where the code says - a rewording changes words, never an address', async () => {
    configure({ 'legal.account.what': 'See https://evil.example for more.', 'anotoki.legal.accountPage': 'Delete it at https://evil.example' });
    const host = await draw(PrivacyFrameComponent);

    expect(host.querySelector('h1')?.textContent?.trim()).toBe('Privacy policy');
    expect(Array.from(host.querySelectorAll('[data-kept]')).map((part) => part.getAttribute('data-kept'))).toEqual([
      'data-0',
      'data-1',
      'data-2',
      'data-3',
      'data-4',
      'data-5',
      'data-6',
      'data-7',
      'data-8',
      'data-9',
      'data-10',
      'data-11',
      'feedback',
      'error-log',
      'audit-log',
      'backups',
    ]);
    // What a feedback message keeps, what for and how long - the reply address only if the sender leaves one, and the
    // account's deletion taking it and the browser out - true of the IAM's (iamFeedback(), scrubFeedback()).
    const feedback = [host.querySelector('[data-kept="feedback"]'), host.querySelector('[data-duration="feedback"]')].map((part) => part?.textContent?.replace(/\s+/g, ' ') ?? '').join(' ');
    for (const said of [
      'a reply address only if the sender leaves one',
      '365 days after it is resolved',
      '30 days after it is marked as spam',
      'the reply address and the browser go from their messages',
    ]) {
      expect(feedback).toContain(said);
    }
    expect(texts(host, '[data-stored] code')).toEqual([
      'anotoki_signed_in',
      'anotoki_iam',
      'anotoki_iam_device',
      'anotoki-iam:device-token',
      'anotoki-iam:theme',
      'anotoki-iam:lang, anotoki-iam:lang-cache',
      'anotoki-iam:lang',
      'anotoki-iam:localization-drafts',
    ]);
    expect(texts(host, '[data-recipient] .anotoki-legal-page__item-name')).toEqual(['WebSupport s.r.o.', 'Cloudflare, Inc.', 'Google', 'WebSupport s.r.o.']);
    expect(texts(host, '[data-recipient] .anotoki-legal-page__item-name + p')[2]).toBe(en['legal.google.role']);
    // Google is contacted only when somebody presses the button (ui/google-button; the owner's decision of 2026-10-14).
    expect(en['legal.google.role']).toMatch(/^Only when you press "Sign in with Google".* does your browser load anything from Google/);
    expect(host.querySelector('[data-section="account"]')?.textContent).toContain('This site is the anotoki account itself');
    expect(host.querySelector('[data-account-privacy]')).toBeNull();

    // The administrator's rewordings are words: the account page's link keeps its address, an address in a fact stays text.
    const deletion = host.querySelector<HTMLAnchorElement>('[data-account-page]')!;
    expect([deletion.getAttribute('href'), deletion.textContent?.trim()]).toEqual(['/account/security', 'Delete it at https://evil.example']);
    expect(host.querySelector('[data-kept="data-0"]')?.textContent).toContain('See https://evil.example for more.');
    const links = Array.from(host.querySelectorAll('a[href]')).map((link) => link.getAttribute('href')!);
    expect(links.filter((href) => !href.startsWith('#') && !ADDRESSES.includes(href))).toEqual([]);
    expect(links).not.toContain('https://evil.example');
  });

  it('the Contact page: the feedback form first - the IAM takes feedback since 2026-10-19 -, the address for everything else, and the account page to delete the account', async () => {
    configure();
    const host = await draw(ContactFrameComponent);
    expect(host.querySelector('[data-section="feedback"] [data-open-feedback]')?.textContent?.trim()).toBe('Send feedback');
    expect(host.querySelector('[data-section="email"] h2')?.textContent?.trim()).toBe('Everything else');
    expect(host.querySelector('[data-section="email"] a')?.getAttribute('href')).toBe('mailto:support@anotoki.eu');
    expect(host.querySelector('[data-account-page]')?.getAttribute('href')).toBe('/account/security');
    expect(host.querySelector('[data-section="delete"]')?.textContent).toContain('each site then keeps what you did there without your name');
  });
});
