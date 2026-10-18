import { TestBed } from '@angular/core/testing';
import { ThemeService, provideAnotokiShell } from '@anotoki/lib/shell';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { Subject, throwError } from 'rxjs';
import { AccountApi } from '../api/account.api';
import { ApiError } from '../api/api-error';
import { AccountUser, ThemeChoice } from '../api/models';
import { SessionService } from '../auth/session.service';
import { THEME_STORAGE_KEY, iamShellConfig } from './kit.config';

/** An account as `GET /api/account` answers it, with a theme of its own (migration 015). */
function account(theme: ThemeChoice, id = 7): AccountUser {
  return {
    id,
    username: id === 7 ? 'Mira' : 'Tomas',
    email: id === 7 ? 'mira@example.test' : 'tomas@example.test',
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
    preferences: { theme, date_format: null, time_format: null },
    deletion_due_at: null,
  };
}

/**
 * The theme as the IAM wires it into the family's shell (iamShellConfig): the
 * library's ThemeService and the IAM's real SessionService, with only the API
 * faked - so what is checked is what the switches in the bar and on the
 * sign-in pages do: signed in, a choice is the account's, saved through the
 * IAM's own API one save at a time; signed out, it is the device's; a refusal
 * puts the account's back; and an answer that comes after Sign out signs
 * nobody back in (SessionService.setFrom()).
 */
describe('the theme, wired to the account (iamShellConfig)', () => {
  const accountApi = { updateProfile: vi.fn(), me: vi.fn() };
  /** The saves sent, each held until the test answers it. */
  let saves: Subject<AccountUser>[];
  let session: SessionService;

  beforeEach(() => {
    localStorage.clear();
    saves = [];
    accountApi.updateProfile.mockReset().mockImplementation(() => {
      const save = new Subject<AccountUser>();
      saves.push(save);
      return save;
    });
    TestBed.configureTestingModule({
      providers: [{ provide: AccountApi, useValue: accountApi }, provideTestTranslations(), provideAnotokiShell(iamShellConfig)],
    });
    session = TestBed.inject(SessionService);
  });

  /** The app's theme service, from its first moment: the account's theme, if somebody is signed in. */
  function themeService(): ThemeService {
    const theme = TestBed.inject(ThemeService);
    TestBed.tick();
    return theme;
  }

  /** Every promise on its way has settled, and the effects have run. */
  async function settled(): Promise<void> {
    await new Promise((done) => setTimeout(done));
    TestBed.tick();
  }

  async function answer(save: Subject<AccountUser>, user: AccountUser): Promise<void> {
    save.next(user);
    save.complete();
    await settled();
  }

  it('signed in, a choice is saved to the account through updateProfile - one save at a time, the latest choice last', async () => {
    session.set(account('auto'));
    const theme = themeService();
    theme.set('dark');
    theme.set('light');
    theme.set('auto');
    // Shown at once. The first save is on its way; the later choices wait for it - only the latest of them.
    expect(theme.mode()).toBe('auto');
    expect(accountApi.updateProfile.mock.calls).toEqual([[{ theme: 'dark' }]]);

    await answer(saves[0], account('dark'));
    expect(accountApi.updateProfile.mock.calls).toEqual([[{ theme: 'dark' }], [{ theme: 'auto' }]]);
    await answer(saves[1], account('auto'));
    expect(accountApi.updateProfile).toHaveBeenCalledTimes(2);
    expect(session.user()?.preferences?.theme).toBe('auto');
    expect(theme.mode()).toBe('auto');
    expect(theme.refused()).toBe(false);
  });

  it("signed out, nothing is sent - nor for an account that keeps no theme yet (015 pending): the choice is the device's", async () => {
    session.clear();
    const theme = themeService();
    theme.set('dark');
    await settled();
    expect(accountApi.updateProfile).not.toHaveBeenCalled();
    expect(theme.mode()).toBe('dark');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');

    session.set({ ...account('auto'), preferences: null });
    await settled();
    theme.set('light');
    await settled();
    expect(accountApi.updateProfile).not.toHaveBeenCalled();
    expect(theme.mode()).toBe('light');
  });

  it("a refusal puts the account's theme back, and the switch says so", async () => {
    const refused: ApiError = { status: 500, code: 'server_error', message: 'x', details: {} };
    accountApi.updateProfile.mockReturnValue(throwError(() => refused));
    session.set(account('dark'));
    const theme = themeService();
    expect(theme.mode()).toBe('dark');

    theme.set('light');
    expect(theme.mode()).toBe('light');
    await settled();
    expect(accountApi.updateProfile).toHaveBeenCalledWith({ theme: 'light' });
    expect(theme.mode()).toBe('dark');
    expect(theme.refused()).toBe(true);
    expect(session.user()?.preferences?.theme).toBe('dark');
    expect(localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark');
  });

  it('an answer that comes after Sign out changes nothing: nobody is signed back in', async () => {
    session.set(account('auto'));
    const theme = themeService();
    theme.set('dark');
    expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);

    // Signed out (the bar's Sign out, a 401 on any request) while the save is on its way; then it is answered.
    session.clear();
    await answer(saves[0], account('dark'));
    expect(session.user()).toBeNull();
    expect(session.signedIn()).toBe(false);
    expect(theme.refused()).toBe(false);

    // The choice stays on the page, the device's now: a new one is nobody's to save.
    expect(theme.mode()).toBe('dark');
    theme.set('light');
    await settled();
    expect(accountApi.updateProfile).toHaveBeenCalledTimes(1);
  });

  it('nor when somebody else has signed in meanwhile: an answer from before Sign out is not their account', async () => {
    session.set(account('auto', 7));
    const theme = themeService();
    theme.set('dark');
    session.clear();
    session.set(account('light', 8));

    await answer(saves[0], account('dark', 7));
    expect(session.user()?.id).toBe(8);
    expect(session.user()?.preferences?.theme).toBe('light');
  });
});
