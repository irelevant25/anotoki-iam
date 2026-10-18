import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component, Type } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { ANOTOKI_ADMIN_CONFIG, AdminFailure, AnotokiAdminConfig, provideAnotokiAdmin } from '@anotoki/lib/admin';
import { AuditLogPageComponent, AuditPage } from '@anotoki/lib/admin/audit';
import { BackupInfo, BackupsAnswer, BackupsPageComponent, BackupsStatus } from '@anotoki/lib/admin/backups';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { ToastService } from '@anotoki/lib/ui/toast';
import { RESTORE_OFF_TEXT, adminToolsPanel } from '../../pages/admin/admin-tools';
import { AccountUser } from '../api/models';
import { SessionService } from '../auth/session.service';
import { iamInterceptor } from '../http/iam.interceptor';
import { iamAdminToolsConfig, useAdminToolsPanel } from './admin-tools.config';

const BACKUPS = '/api/admin/backups';
const BACKUP_ID = '20261011-030000Z-daily-0123456789abcdef0123456789abcdef';

/** An IAM administrator as `GET /api/account` answers them; `has_password: false` is a Google-only account. */
function account(overrides: Partial<AccountUser> = {}): AccountUser {
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
    is_admin: true,
    news_mail: false,
    preferences: { theme: 'auto', date_format: null, time_format: null },
    deletion_due_at: null,
    ...overrides,
  };
}

/** The IAM's backups as its server lists them: encrypted with a key from the master key, restoring switched off. */
function backupsAnswer(): BackupsAnswer {
  const backup: BackupInfo = {
    id: BACKUP_ID,
    kind: 'daily',
    created_at: '2026-10-11T03:00:00.000Z',
    encrypted: true,
    status: 'complete',
    size: 1_572_864,
    created_by: null,
    description: null,
    table_count: 31,
    rows: 2_310,
    restorable: false,
  };
  const status: BackupsStatus = {
    directory_writable: true,
    free_bytes: 8 * 1024 ** 3,
    last_daily: '2026-10-11T03:00:00.000Z',
    copy_available: true,
    encrypted: true,
    cipher_available: true,
    restore_enabled: false,
    restorable: false,
    problems: [],
    count: 1,
    total_bytes: 1_572_864,
    database_bytes: 14 * 1024 ** 2,
  };
  return { backups: [backup], status };
}

@Component({ selector: 'app-nowhere', template: '', changeDetection: ChangeDetectionStrategy.OnPush })
class NowhereComponent {}

/**
 * The family's admin tools as the IAM wires them (core/admin/admin-tools.config.ts,
 * pages/admin/admin-tools.ts): the library's own pages, drawn for real, behind
 * the IAM's real interceptor and session - so what is checked is what the
 * admin panel does: the pages are the IAM's ADMIN's, a refusal is said in the
 * panel's words, every write carries the CSRF header, the proof is the IAM's
 * own (the password; for an account without one, "Sign in again", which signs
 * out and comes back), and the Backups page says why the IAM restores nothing
 * in place.
 */
describe("the family's admin tools, wired for the IAM (iamAdminToolsConfig)", () => {
  let http: HttpTestingController;
  let session: SessionService;
  let router: Router;
  let config: AnotokiAdminConfig;
  let fixture: ComponentFixture<unknown> | null;
  let host: HTMLElement;

  beforeEach(async () => {
    useAdminToolsPanel(null);
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'admin/backups', component: NowhereComponent },
          { path: 'admin/audit', component: NowhereComponent },
          { path: 'login', component: NowhereComponent },
        ]),
        provideHttpClient(withInterceptors([iamInterceptor])),
        provideHttpClientTesting(),
        provideTestTranslations(),
        provideAnotokiAdmin(iamAdminToolsConfig),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionService);
    router = TestBed.inject(Router);
    config = TestBed.inject(ANOTOKI_ADMIN_CONFIG);
    session.set(account());
    fixture = null;
  });

  afterEach(() => {
    http.verify();
    fixture?.destroy();
    document.body.innerHTML = '';
    vi.restoreAllMocks();
    useAdminToolsPanel(null);
  });

  /** What the admin panel's routes do as they load: the panel's part handed over. */
  function handOver(): void {
    TestBed.runInInjectionContext(() => useAdminToolsPanel(adminToolsPanel()));
  }

  /** Lets the requests' promises, timers of 0 and change detection run out. */
  async function settle(): Promise<void> {
    for (let i = 0; i < 4; i++) {
      await new Promise((done) => setTimeout(done));
      await fixture?.whenStable();
    }
  }

  async function draw(page: Type<unknown>): Promise<void> {
    fixture = TestBed.createComponent(page);
    host = fixture.nativeElement as HTMLElement;
    document.body.appendChild(host);
    await settle();
  }

  const words = (element: Element | null | undefined): string => (element?.textContent ?? '').replace(/\s+/g, ' ').trim();
  const find = <T extends Element = HTMLElement>(selector: string): T => {
    const element = host.querySelector<T>(selector);
    if (!element) {
      throw new Error(`Nothing matches ${selector}.`);
    }
    return element;
  };
  const click = (selector: string): void => find(selector).click();
  function type(selector: string, value: string): void {
    const field = find<HTMLInputElement>(selector);
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
  }
  const request = (method: string, url: string): TestRequest => http.expectOne((each) => each.method === method && each.url === url, `${method} ${url}`);

  /** The Backups page at /admin/backups, its list and its proof answered. */
  async function openBackups(proof: 'password' | 'recent_sign_in'): Promise<void> {
    await router.navigateByUrl('/admin/backups');
    await draw(BackupsPageComponent);
    request('GET', BACKUPS).flush(backupsAnswer());
    request('GET', `${BACKUPS}/proof`).flush({ kind: proof });
    await settle();
  }

  it("is the IAM's ADMIN's - its session's is_admin, which a pending deletion switches off - and names the IAM, its Users and Migrations pages", () => {
    expect(config.isAdmin()).toBe(true);
    session.set(account({ is_admin: false, deletion_due_at: '2026-11-10T00:00:00Z' }));
    expect(config.isAdmin()).toBe(false);
    session.clear();
    expect(config.isAdmin()).toBe(false);

    expect(config.siteName).toBe('the IAM');
    expect(config.migrationsRoute).toBe('/admin/migrations');
    expect(config.personLink?.(42)).toEqual(['/admin/users', 42]);
  });

  it('writes a date as the account does (its date order and clock, migration 015)', () => {
    session.set(account({ preferences: { theme: 'auto', date_format: 'ymd_dash', time_format: '24' } }));
    const when = new Date('2026-10-11T03:00:00Z');
    const day = `${when.getFullYear()}-${String(when.getMonth() + 1).padStart(2, '0')}-${String(when.getDate()).padStart(2, '0')}`;
    expect(config.formatDate?.(when.toISOString())).toMatch(new RegExp(`^${day} \\d{2}:\\d{2}$`));
  });

  it("says a refusal in the panel's words, and why restoring is off - once the panel's routes have handed them over", () => {
    const forbidden: AdminFailure = { status: 403, code: 'forbidden', message: 'Forbidden', details: {} };
    const waiting: AdminFailure = { status: 503, code: 'feature_unavailable', message: 'Not yet.', details: {} };
    // Before: the pages' own words (no page can be made before the panel's routes have loaded).
    expect(config.failureText?.(forbidden)).toBeUndefined();
    expect(config.restoreOffText).toBeNull();

    handOver();
    expect(config.failureText?.(forbidden)).toBe('Only IAM administrators can do this.');
    expect(config.failureText?.(waiting)).toBe('Apply the pending update first: Admin panel → Migrations.');
    expect(config.restoreOffText).toBe(RESTORE_OFF_TEXT);
  });

  it("the Backups page: encrypted, restoring off with the IAM's reason, and Back up now behind the password - sent with the CSRF header", async () => {
    handOver();
    await openBackups('password');

    expect(words(host.querySelector('[data-restore-off]'))).toContain(RESTORE_OFF_TEXT);
    expect(host.querySelector('[data-restore]')).toBeNull();
    expect(words(host.querySelector('[data-status]'))).toContain('Encrypted, AES-256-GCM');

    click('[data-create]');
    await settle();
    const password = find<HTMLInputElement>('anotoki-dialog[data-guarded] [data-password] input');
    expect(password.type).toBe('password');
    expect(password.autocomplete).toBe('current-password');
    type('anotoki-dialog[data-guarded] [data-password] input', 'not my password');
    await settle();
    click('[data-guarded-confirm]');
    const wrong = request('POST', BACKUPS);
    expect(wrong.request.headers.get('X-IAM-Request')).toBe('1');
    expect(wrong.request.body).toEqual({ password: 'not my password' });
    wrong.flush({ error: 'The current password is not right', code: 'wrong_password' }, { status: 422, statusText: 'Unprocessable Content' });
    await settle();
    // Said beside the password, in the server's words; the dialog stays.
    expect(words(host.querySelector('anotoki-dialog[data-guarded] [data-password]'))).toContain('The current password is not right');

    type('anotoki-dialog[data-guarded] [data-password] input', 'admin password 1');
    await settle();
    click('[data-guarded-confirm]');
    const made = request('POST', BACKUPS);
    expect(made.request.headers.get('X-IAM-Request')).toBe('1');
    expect(made.request.body).toEqual({ password: 'admin password 1' });
    made.flush({ backup: { ...backupsAnswer().backups[0], kind: 'manual', created_by: 7 } }, { status: 201, statusText: 'Created' });
    await settle();
    request('GET', BACKUPS).flush(backupsAnswer());
    await settle();
    expect(words(host.querySelector('[data-outcome]'))).toContain('Backed up');
    expect(host.querySelector('anotoki-dialog[data-guarded]')).toBeNull();
  });

  it('a download: the password, then the single-use link - opened by the page, the ticket asked with the CSRF header', async () => {
    handOver();
    const opened: string[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      opened.push(this.getAttribute('href') ?? '');
    });
    await openBackups('password');

    click('[data-download]');
    await settle();
    expect(host.querySelector('anotoki-dialog[data-guarded]')?.getAttribute('data-action')).toBe('download');
    type('anotoki-dialog[data-guarded] [data-password] input', 'admin password 1');
    await settle();
    click('[data-guarded-confirm]');
    const ticket = request('POST', `${BACKUPS}/${BACKUP_ID}/ticket`);
    expect(ticket.request.headers.get('X-IAM-Request')).toBe('1');
    expect(ticket.request.body).toEqual({ password: 'admin password 1' });
    ticket.flush({ url: `${BACKUPS}/${BACKUP_ID}/file?ticket=0f1e2d3c`, expires_in: 60 });
    await settle();
    expect(opened).toEqual([`${BACKUPS}/${BACKUP_ID}/file?ticket=0f1e2d3c`]);
  });

  it('an account without a password, its sign-in too old: Sign in again signs out and opens the sign-in page, back to Backups', async () => {
    handOver();
    session.set(account({ has_password: false, google_connected: true }));
    await openBackups('recent_sign_in');

    // Straight away, as the sites do; the IAM refuses an old sign-in (accountProof()).
    click('[data-download]');
    request('POST', `${BACKUPS}/${BACKUP_ID}/ticket`).flush(
      { error: 'Sign in again first: without a password, this needs a sign-in of the last 15 minutes', code: 'reauthentication_required' },
      { status: 403, statusText: 'Forbidden' },
    );
    await settle();
    expect(host.querySelector('anotoki-dialog[data-guarded] [data-sign-in-needed]')).not.toBeNull();

    click('anotoki-dialog[data-guarded] [data-sign-in]');
    const logout = request('POST', '/api/logout');
    expect(logout.request.headers.get('X-IAM-Request')).toBe('1');
    logout.flush({ message: 'Signed out.', redirect: null });
    await settle();
    expect(session.user()).toBeNull();
    expect(router.url).toBe('/login?next=%2Fadmin%2Fbackups');
  });

  it("should signing out fail, a toast says so in the panel's words, and nothing moves", async () => {
    handOver();
    session.set(account({ has_password: false, google_connected: true }));
    const toast = vi.spyOn(TestBed.inject(ToastService), 'error');
    await openBackups('recent_sign_in');

    click('[data-download]');
    request('POST', `${BACKUPS}/${BACKUP_ID}/ticket`).flush({ error: 'Sign in again first', code: 'reauthentication_required' }, { status: 403, statusText: 'Forbidden' });
    await settle();
    click('anotoki-dialog[data-guarded] [data-sign-in]');
    request('POST', '/api/logout').error(new ProgressEvent('error'));
    await settle();

    expect(toast).toHaveBeenCalledWith('Could not sign you out to sign in again. The server could not be reached. Check the connection and try again.');
    expect(session.user()).not.toBeNull();
    expect(router.url).toBe('/admin/backups');
  });

  it("a page's refusal reaches it reshaped by the IAM's interceptor, and is said in the panel's words", async () => {
    handOver();
    await router.navigateByUrl('/admin/backups');
    await draw(BackupsPageComponent);
    request('GET', BACKUPS).flush({ error: 'Forbidden', code: 'forbidden' }, { status: 403, statusText: 'Forbidden' });
    request('GET', `${BACKUPS}/proof`).flush({ kind: 'password' });
    await settle();
    expect(words(host.querySelector('anotoki-error-state'))).toContain('Only IAM administrators can do this.');
  });

  it('the Audit log names whoever acted as a link to their page under Users', async () => {
    handOver();
    await router.navigateByUrl('/admin/audit');
    await draw(AuditLogPageComponent);
    const page: AuditPage = {
      total: 1,
      page: 1,
      pageSize: 50,
      items: [
        {
          id: 12,
          created_at: '2026-10-11T08:00:00.000Z',
          actor_id: 7,
          actor_username: 'mira',
          actor_session: null,
          action: 'user.roles_set',
          target_type: 'user',
          target_id: '9',
          scope_type: null,
          scope_id: null,
          details: { before: [], after: ['genshin:EDITOR'] },
          ip: '192.0.2.10',
        },
      ],
    };
    http.expectOne((each) => each.method === 'GET' && each.url === '/api/admin/audit').flush(page);
    request('GET', '/api/admin/audit/facets').flush({ actions: ['user.roles_set'], target_types: ['user'], scope_types: [], actors: [{ id: 7, username: 'mira' }] });
    await settle();
    const actor = [...host.querySelectorAll<HTMLAnchorElement>('a')].find((link) => words(link) === 'mira');
    expect(actor?.getAttribute('href')).toBe('/admin/users/7');
  });
});
