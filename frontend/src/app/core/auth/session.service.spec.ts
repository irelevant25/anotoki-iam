import { TestBed } from '@angular/core/testing';
import { Subject, firstValueFrom, of, throwError } from 'rxjs';
import { AccountApi } from '../api/account.api';
import { ApiError } from '../api/api-error';
import { AccountUser } from '../api/models';
import { SessionService } from './session.service';

/** An account as `GET /api/account` answers it. */
function account(username = 'Mira', id = 7): AccountUser {
  return {
    id,
    username,
    email: `${username.toLowerCase()}@example.test`,
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
    news_mail: true,
    preferences: { theme: 'auto', date_format: null, time_format: null },
    deletion_due_at: null,
  };
}

/**
 * Who is signed in, as this tab knows it - and the one rule every save that
 * answers with the account keeps: the account pages' (the name, the mails,
 * the dates, the password, two-factor, Google, the deletion and its
 * cancelling), the theme switch's and the language chosen on any page (the
 * family's translations, core/i18n/translations.config.ts) all go through
 * setFrom() (or refresh()), so an answer that comes after Sign out signs
 * nobody back in. Only a sign-in's answer and the probe's are the session as
 * they are.
 */
describe('SessionService', () => {
  const accountApi = { me: vi.fn() };
  let session: SessionService;

  beforeEach(() => {
    accountApi.me.mockReset();
    TestBed.configureTestingModule({ providers: [{ provide: AccountApi, useValue: accountApi }] });
    session = TestBed.inject(SessionService);
  });

  it('a save that answers with the account (setFrom): the session from then on - unless Sign out came first; the page hears it either way', async () => {
    session.set(account());

    // Answered while signed in: the account as it now is.
    const renamed = { ...account(), username: 'Mirka' };
    expect(await firstValueFrom(session.setFrom(of(renamed)))).toBe(renamed);
    expect(session.user()?.username).toBe('Mirka');

    // Sent, then Sign out, then answered: dropped - though the page that sent it still hears it (its spinner, its words).
    const late = new Subject<AccountUser>();
    const heard = firstValueFrom(session.setFrom(late));
    session.clear();
    late.next({ ...account(), username: 'Late' });
    expect((await heard).username).toBe('Late');
    expect(session.user()).toBeNull();
    expect(session.signedIn()).toBe(false);

    // Sent, then Sign out and somebody else signs in, then answered: not their account.
    session.set(account());
    const earlier = new Subject<AccountUser>();
    const waiting = firstValueFrom(session.setFrom(earlier));
    session.clear();
    session.set(account('Tomas', 8));
    earlier.next(account('Mira', 7));
    await waiting;
    expect(session.user()?.id).toBe(8);

    // Nor when the sign-in came without a Sign out (signing in on the sign-in page, signed in already): a sign-in starts anew.
    const before = new Subject<AccountUser>();
    const pending = firstValueFrom(session.setFrom(before));
    session.set(account('Mira', 7));
    before.next(account('Tomas', 8));
    await pending;
    expect(session.user()?.id).toBe(7);

    // A refusal is passed on, and changes nothing.
    const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
    await expect(firstValueFrom(session.setFrom(throwError(() => refused)))).rejects.toBe(refused);
    expect(session.user()?.id).toBe(7);
  });

  it('refresh() keeps the account it reads the same way: read before Sign out, it signs nobody back in', async () => {
    const answer = new Subject<AccountUser>();
    accountApi.me.mockReturnValue(answer);
    session.set(account());
    const read = firstValueFrom(session.refresh());
    session.clear();
    answer.next(account());
    await read;
    expect(session.user()).toBeNull();

    accountApi.me.mockReturnValue(of({ ...account(), trusted_devices: 0 }));
    session.set({ ...account(), trusted_devices: 3 });
    await firstValueFrom(session.refresh());
    expect(session.user()?.trusted_devices).toBe(0);
  });

  it('a sign-in’s answer and the probe’s are the session as they are, after a Sign out too', async () => {
    session.set(account());
    session.clear();
    session.set(account('Tomas', 8));
    expect(session.user()?.id).toBe(8);

    session.clear();
    accountApi.me.mockReturnValue(of(account()));
    expect((await firstValueFrom(session.probe()))?.id).toBe(7);
    expect(accountApi.me).toHaveBeenCalledWith({ probe: true });
    expect(session.user()?.id).toBe(7);
  });
});
