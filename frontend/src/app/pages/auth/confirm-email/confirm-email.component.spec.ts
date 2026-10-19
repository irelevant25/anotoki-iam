import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { AccountUser } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { iamInterceptor } from '../../../core/http/iam.interceptor';
import { en } from '../../../core/i18n/en';
import { iamUiConfig } from '../../../core/kit/kit.config';
import { ConfirmEmailComponent } from './confirm-email.component';

const TOKEN = 'ab'.repeat(32);

/** An account as `GET /api/account` answers it. */
function account(id: number, email: string): AccountUser {
  return {
    id,
    username: email.split('@')[0],
    email,
    email_confirmed: true,
    language: 'en',
    created_at: '2026-10-19T10:00:00Z',
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
    preferences: null,
    deletion_due_at: null,
  };
}

/**
 * /confirm-email (the security review of 2026-10-19): the link is sent only
 * when the person presses "Confirm my address" - opening somebody else's link
 * confirms nothing and signs nobody in -; the server's answer decides the
 * rest, and an account this browser is signed in to stays signed in when the
 * link was another account's (backend tests/confirmation-links.test.php holds
 * the server to it).
 */
describe('the confirmation page: nothing sent before the press, and another account’s session left as it is', () => {
  let fixture: ComponentFixture<ConfirmEmailComponent> | null = null;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), provideHttpClient(withInterceptors([iamInterceptor])), provideHttpClientTesting(), provideTestTranslations(en), provideAnotokiUi(iamUiConfig)],
    });
    http = TestBed.inject(HttpTestingController);
    window.history.replaceState(null, '', `/confirm-email?token=${TOKEN}`);
  });

  afterEach(() => {
    fixture?.destroy();
    fixture = null;
    http.verify();
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/');
  });

  /** The page as the mailed link opens it - or, with null, as it opens with no link. */
  async function open(token: string | null = TOKEN): Promise<HTMLElement> {
    fixture = TestBed.createComponent(ConfirmEmailComponent);
    fixture.componentRef.setInput('token', token ?? undefined);
    fixture.detectChanges();
    await settled();
    return fixture.nativeElement as HTMLElement;
  }

  async function settled(): Promise<void> {
    await new Promise((done) => setTimeout(done));
    await fixture?.whenStable();
  }

  const heading = (host: HTMLElement) => host.querySelector('h1')?.textContent?.trim();
  const control = (host: HTMLElement, text: string) => Array.from(host.querySelectorAll<HTMLElement>('button, a')).find((element) => element.textContent?.trim() === text) ?? null;

  /** The press, and the request it sends. */
  async function press(host: HTMLElement, text: string = en['confirm.submit']) {
    control(host, text)!.click();
    await settled();
    return http.expectOne('/api/register/confirm');
  }

  it('opening the link sends nothing: the token leaves the address bar, and the page asks for a press', async () => {
    const host = await open();

    http.expectNone('/api/register/confirm');
    expect(window.location.search).toBe('');
    expect(heading(host)).toBe(en['confirm.pageTitle']);
    expect(host.textContent).toContain(en['confirm.readyText']);
    expect(control(host, en['confirm.submit'])?.tagName).toBe('BUTTON');
  });

  it('the press sends the link, under the CSRF header; signed in, the account is the session’s and the way back is offered', async () => {
    const host = await open();
    const sent = await press(host);
    expect(sent.request.method).toBe('POST');
    expect(sent.request.body).toEqual({ token: TOKEN });
    expect(sent.request.headers.get('X-IAM-Request')).toBe('1');

    const user = account(7, 'newcomer@example.test');
    sent.flush({ signed_in: true, user, device_token: null, continue_to: { name: 'Genshin Impact', url: 'http://localhost:4200/' } });
    await settled();

    expect(TestBed.inject(SessionService).user()).toEqual(user);
    expect(heading(host)).toBe(en['confirm.doneTitle']);
    expect(control(host, 'Continue to Genshin Impact')).not.toBeNull();
  });

  it('another account signed in here: the address confirmed, and this browser stays signed in to it - the session untouched, and a way to sign in with the confirmed one', async () => {
    const session = TestBed.inject(SessionService);
    const signedIn = account(3, 'victim@example.test');
    session.set(signedIn);
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    const host = await open();

    (await press(host)).flush({ signed_in: false, reason: 'other_account' });
    await settled();

    expect(session.user()).toEqual(signedIn);
    expect(heading(host)).toBe(en['confirm.doneTitle']);
    expect(host.textContent).toContain(en['confirm.otherAccountText']);
    expect(control(host, en['common.goToAccount'])?.getAttribute('href')).toBe('/account');

    control(host, en['confirm.signOutAndIn'])!.click();
    await settled();
    expect(navigate).not.toHaveBeenCalled();
    http.expectOne('/api/logout').flush({ message: 'Signed out', redirect: null });
    await settled();
    expect(session.user()).toBeNull();
    expect(navigate).toHaveBeenCalledWith('/login');
  });

  it('an address confirmed already - an older link, or this one used before: said so, the sign-in page offered, nobody signed in', async () => {
    const session = TestBed.inject(SessionService);
    const host = await open();

    (await press(host)).flush({ signed_in: false, reason: 'already_confirmed' });
    await settled();

    expect(session.user()).toBeUndefined();
    expect(heading(host)).toBe(en['confirm.doneTitle']);
    expect(host.textContent).toContain(en['confirm.signInText']);
    expect(control(host, en['common.signIn'])?.getAttribute('href')).toBe('/login');
  });

  it('two-factor on: the code asked for, the same link sent again with it, and a wrong one said under the field', async () => {
    const host = await open();
    (await press(host)).flush({ code: 'totp_required', error: 'A code from your authenticator app is needed' }, { status: 403, statusText: 'Forbidden' });
    await settled();

    expect(heading(host)).toBe(en['login.totp.title']);
    expect(host.textContent).toContain(en['confirm.totpText']);
    const field = host.querySelector<HTMLInputElement>('input[name="totp"]')!;
    field.value = '123456';
    field.dispatchEvent(new Event('input'));
    await settled();

    const wrong = await press(host, en['login.totp.submit']);
    expect(wrong.request.body).toEqual({ token: TOKEN, totp: '123456' });
    wrong.flush({ code: 'totp_invalid', error: 'That code is not right' }, { status: 403, statusText: 'Forbidden' });
    await settled();
    expect(host.querySelector('[role="alert"]')?.textContent?.trim()).toBe(en['error.totpInvalid']);

    field.value = '654321';
    field.dispatchEvent(new Event('input'));
    await settled();
    const right = await press(host, en['login.totp.submit']);
    expect(right.request.body).toEqual({ token: TOKEN, totp: '654321' });
    right.flush({ signed_in: true, user: account(9, 'imported@example.test'), device_token: null, continue_to: null });
    await settled();
    expect(heading(host)).toBe(en['confirm.doneTitle']);
  });

  it('a link that no longer works: said so, with a way to have a new one mailed', async () => {
    const host = await open();
    (await press(host)).flush({ code: 'invalid_token', error: 'That link is no longer valid' }, { status: 400, statusText: 'Bad Request' });
    await settled();

    expect(heading(host)).toBe(en['confirm.failedTitle']);
    expect(host.textContent).toContain(en['confirm.failedText']);
    expect(control(host, en['confirm.resend'])).not.toBeNull();
  });

  it('no link in the address: nothing to confirm, and nothing sent', async () => {
    window.history.replaceState(null, '', '/confirm-email');
    const host = await open(null);

    http.expectNone('/api/register/confirm');
    expect(heading(host)).toBe(en['confirm.missingTitle']);
    expect(control(host, en['confirm.submit'])).toBeNull();
  });
});
