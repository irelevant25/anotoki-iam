import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component, ErrorHandler } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { ANOTOKI_ERROR_REPORT, ErrorReport, provideAnotokiErrorReport } from '@anotoki/lib/error-report';
import { AccountUser } from '../api/models';
import { SessionService } from '../auth/session.service';
import { iamInterceptor } from './iam.interceptor';

const REPORTS = '/api/client-errors';

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
    is_admin: true,
    news_mail: false,
    preferences: { theme: 'auto', date_format: null, time_format: null },
    deletion_due_at: null,
  };
}

@Component({ selector: 'app-nowhere', template: '', changeDetection: ChangeDetectionStrategy.OnPush })
class NowhereComponent {}

/**
 * The pages' own errors as the IAM reports them: the family's reporter
 * (provideAnotokiErrorReport(), app.config.ts) through the IAM's interceptor,
 * as the app has them. The report is a write like any other - it carries the
 * CSRF header, never an exemption - but no request of the person's: whatever
 * it fails with moves no page and clears no session, and it reaches the
 * reporter as it came, so a 429 stops the reporting for the page load.
 */
describe("the pages' own errors, reported through the IAM's interceptor", () => {
  let http: HttpTestingController;
  let session: SessionService;
  let router: Router;
  let handler: ErrorHandler;

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'account/security', component: NowhereComponent },
          { path: 'login', component: NowhereComponent },
          { path: 'not-set-up', component: NowhereComponent },
        ]),
        provideHttpClient(withInterceptors([iamInterceptor])),
        provideHttpClientTesting(),
        provideAnotokiErrorReport(),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionService);
    router = TestBed.inject(Router);
    handler = TestBed.inject(ErrorHandler);
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    session.set(account());
    await router.navigateByUrl('/account/security');
  });

  afterEach(() => {
    http.verify();
    vi.restoreAllMocks();
  });

  /** Lets the reporter's timer of 0 and the requests' answers run out. */
  const settle = () => new Promise((done) => setTimeout(done, 5));
  const reports = (): TestRequest[] => http.match((request) => request.method === 'POST' && request.url === REPORTS);

  it("sends a report with the CSRF header every write carries, marked as the reporter's own", async () => {
    handler.handleError(new TypeError('Cannot read the page'));
    await settle();

    const [report] = reports();
    expect(report.request.headers.get('X-IAM-Request')).toBe('1');
    expect(report.request.context.get(ANOTOKI_ERROR_REPORT)).toBe(true);
    expect((report.request.body as ErrorReport).message).toBe('Cannot read the page');
    report.flush(null, { status: 204, statusText: 'No Content' });
  });

  it('moves no page and clears no session for a report the server refuses - an ended session, an IAM not set up', async () => {
    handler.handleError(new Error('First'));
    await settle();
    reports()[0].flush({ error: 'Sign in first', code: 'no_session' }, { status: 401, statusText: 'Unauthorized' });
    handler.handleError(new Error('Second'));
    await settle();
    reports()[0].flush({ code: 'not_set_up', installed: true, message: 'Not set up.' }, { status: 503, statusText: 'Service Unavailable' });
    await settle();

    expect(session.signedIn()).toBe(true);
    expect(router.url).toBe('/account/security');
  });

  it('hands the reporter its failure as it came, so a 429 stops the reporting for the page load', async () => {
    handler.handleError(new Error('One'));
    await settle();
    reports()[0].flush({ error: 'That is a lot of errors.', code: 'too_many', retry_after: 60 }, { status: 429, statusText: 'Too Many Requests' });

    handler.handleError(new Error('Two'));
    await settle();
    expect(reports()).toHaveLength(0);
  });

  it("while a request of the person's own still does what it always did: an ended session is the sign-in page", async () => {
    TestBed.inject(HttpClient)
      .post('/api/account/profile', {})
      .subscribe({ error: () => undefined });
    const request = http.expectOne('/api/account/profile');
    expect(request.request.headers.get('X-IAM-Request')).toBe('1');
    request.flush({ error: 'Sign in first', code: 'no_session' }, { status: 401, statusText: 'Unauthorized' });
    await settle();

    expect(session.signedIn()).toBe(false);
    expect(router.url).toBe('/login?next=%2Faccount%2Fsecurity');
  });
});
