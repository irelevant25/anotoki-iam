import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { ANOTOKI_FEEDBACK_CONFIG, provideAnotokiFeedback } from '@anotoki/lib/feedback';
import { FeedbackFormComponent } from '@anotoki/lib/feedback/form';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { AccountUser } from '../api/models';
import { SessionService } from '../auth/session.service';
import { iamInterceptor } from '../http/iam.interceptor';
import { en } from '../i18n/en';
import { iamUiConfig } from '../kit/kit.config';
import { iamFeedbackConfig } from './feedback.config';

const FEEDBACK = '/api/feedback';

function account(): AccountUser {
  return {
    id: 7,
    username: 'mira',
    email: 'mira@example.test',
    email_confirmed: true,
    language: 'en',
    created_at: '2026-01-01T00:00:00Z',
    has_password: true,
    password_login_enabled: true,
    google_connected: false,
    google_email: null,
    totp_enabled: false,
    recovery_codes_remaining: 0,
    trusted_devices: 0,
    force_password_change: false,
    is_admin: false,
    news_mail: false,
    preferences: { theme: 'auto', date_format: null, time_format: null },
    deletion_due_at: null,
  };
}

@Component({ selector: 'app-nowhere', template: '', changeDetection: ChangeDetectionStrategy.OnPush })
class NowhereComponent {}

/**
 * The family's feedback form as the IAM sets it up (core/feedback/feedback.config.ts),
 * drawn for real behind the IAM's interceptor and session: in the page's
 * language and the database's words; for the person signed in, "send it
 * without my name" and their address written in until they type their own;
 * for anybody else neither - and the session asked first where the page never
 * needed it, as the server takes the sender from the session cookie whatever
 * the page knows. The message carries the CSRF header, and whatever it is
 * refused with is the form's to say, in the reader's words: no page moves and
 * no session ends for it.
 */
describe('the feedback form, as the IAM sets it up', () => {
  let fixture: ComponentFixture<FeedbackFormComponent> | null = null;
  let host: HTMLElement;
  let http: HttpTestingController;
  let session: SessionService;

  /** The IAM's app around the form, at `url`, the session as the test leaves it. */
  async function configure(url: string, values: Record<string, string> = {}, language: 'en' | 'sk' = 'en'): Promise<void> {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'account/security', component: NowhereComponent },
          { path: 'register', component: NowhereComponent },
          { path: 'login', component: NowhereComponent },
        ]),
        provideHttpClient(withInterceptors([iamInterceptor])),
        provideHttpClientTesting(),
        language === 'sk' ? provideTestTranslations({ ...en, ...values }, { language: 'sk', english: en }) : provideTestTranslations({ ...en, ...values }),
        provideAnotokiUi(iamUiConfig),
        provideAnotokiFeedback(iamFeedbackConfig),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionService);
    await TestBed.inject(Router).navigateByUrl(url);
  }

  async function draw(): Promise<void> {
    fixture = TestBed.createComponent(FeedbackFormComponent);
    host = fixture.nativeElement;
    document.body.appendChild(host);
    await fixture.whenStable();
  }

  async function settle(): Promise<void> {
    for (let i = 0; i < 3; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture?.whenStable();
    }
  }

  /** Writes a message into the form and presses Send: the request it made. */
  async function send(message: string): Promise<TestRequest> {
    const field = host.querySelector<HTMLTextAreaElement>('[data-message] textarea')!;
    field.value = message;
    field.dispatchEvent(new Event('input'));
    await fixture!.whenStable();
    host.querySelector<HTMLButtonElement>('[data-send]')!.click();
    await fixture!.whenStable();
    return http.expectOne(FEEDBACK);
  }

  const failure = () => host.querySelector('[data-failure]')?.textContent?.replace(/\s+/g, ' ').trim() ?? null;

  afterEach(() => {
    http.verify();
    fixture?.destroy();
    fixture = null;
    document.body.innerHTML = '';
  });

  it('for the person signed in: "send it without my name", and the account’s address written in until they type their own', async () => {
    await configure('/account/security');
    session.set(account());
    await draw();
    expect(host.querySelector('[data-anonymous]')).not.toBeNull();
    expect(host.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe('mira@example.test');
    const config = TestBed.inject(ANOTOKI_FEEDBACK_CONFIG);
    expect([config.signedIn?.(), config.email?.(), config.language?.()]).toEqual([true, 'mira@example.test', 'en']);
  });

  it('for anybody else: no "without my name" - there is no name to leave out - and no address', async () => {
    await configure('/account/security');
    session.clear();
    await draw();
    expect(host.querySelector('[data-anonymous]')).toBeNull();
    expect(host.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe('');
  });

  it('on a page that never asked who is signed in, the form asks first - the server takes the sender from the session cookie, so the form must know it too', async () => {
    await configure('/register');
    expect(session.known()).toBe(false);
    await draw();
    const asked = http.expectOne('/api/account');
    asked.flush(account());
    await settle();
    expect(session.signedIn()).toBe(true);
    expect(host.querySelector('[data-anonymous]')).not.toBeNull();
    expect(host.querySelector<HTMLInputElement>('[data-email] input')?.value).toBe('mira@example.test');
  });

  it('its words: the database’s - an owner’s rewording - in the page’s language, the library’s own Slovak (ty) under them', async () => {
    await configure('/account/security', { 'anotoki.feedback.title': 'Napíš nám' }, 'sk');
    session.clear();
    await draw();
    expect(host.getAttribute('lang')).toBe('sk');
    expect(host.querySelector('.anotoki-feedback-form__title')?.textContent?.trim()).toBe('Napíš nám');
    expect(host.querySelector('.anotoki-feedback-form__lead')?.textContent?.trim()).toBe('Niečo nefunguje, máš nápad alebo otázku? Napíš nám - čítame každú správu.');
  });

  it('sends what was written to the IAM’s own route with the CSRF header every write carries - and thanks', async () => {
    await configure('/account/security');
    session.set(account());
    await draw();
    const sent = await send('The Security page jumps when I press Save.');
    expect(sent.request.method).toBe('POST');
    expect(sent.request.headers.get('X-IAM-Request')).toBe('1');
    expect(sent.request.body).toEqual({
      kind: 'bug',
      message: 'The Security page jumps when I press Save.',
      email: 'mira@example.test',
      page: location.pathname,
      language: 'en',
      website: '',
    });
    sent.flush({ ok: true }, { status: 201, statusText: 'Created' });
    await settle();
    expect(host.querySelector('[data-thanks]')).not.toBeNull();
  });

  it('a refusal reaches the form as it came, said in the reader’s words - too many, a field too long, not taking messages now', async () => {
    await configure('/account/security');
    session.set(account());
    await draw();

    (await send('One more message.')).flush({ error: 'That is a lot of messages in one hour.', code: 'too_many', retry_after: 1200 }, { status: 429, statusText: 'Too Many Requests' });
    await settle();
    expect(failure()).toBe('Thank you - but that is a lot of messages in a short while. Try again in an hour.');

    (await send('Another message.')).flush({ error: 'The site has had a lot of messages today.', code: 'too_many', retry_after: 80000 }, { status: 429, statusText: 'Too Many Requests' });
    await settle();
    expect(failure()).toBe('Thank you - but there have been a lot of messages today. Try again tomorrow.');

    (await send('A third message.')).flush(
      { error: 'The message is longer than 10000 characters.', code: 'too_long', field: 'message', max: 10000 },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    await settle();
    expect(host.querySelector('[data-message]')?.textContent).toContain('This is longer than 10000 characters.');

    (await send('A fourth message.')).flush({ error: 'Feedback is not open yet.', code: 'feature_unavailable' }, { status: 503, statusText: 'Service Unavailable' });
    await settle();
    expect(failure()).toBe('Messages cannot be sent right now. Try again in a few minutes.');
  });

  it('moves no page and ends no session for whatever the message is refused with - a 401, the IAM not set up', async () => {
    await configure('/account/security');
    session.set(account());
    await draw();
    (await send('A message.')).flush({ error: 'Sign in first', code: 'no_session' }, { status: 401, statusText: 'Unauthorized' });
    await settle();
    (await send('A message again.')).flush({ code: 'not_set_up', installed: true, message: 'Not set up.' }, { status: 503, statusText: 'Service Unavailable' });
    await settle();
    expect(session.signedIn()).toBe(true);
    expect(TestBed.inject(Router).url).toBe('/account/security');
    expect(host.querySelector<HTMLTextAreaElement>('[data-message] textarea')?.value, 'what was written stays').toBe('A message again.');
  });
});
