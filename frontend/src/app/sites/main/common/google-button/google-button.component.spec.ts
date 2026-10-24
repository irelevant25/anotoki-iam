import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { ThemeService } from '@anotoki/lib/shell';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { PublicConfig } from '../../../../api/models';
import { GIS_SCRIPT, GoogleSignInService } from '../google/google-sign-in.service';
import { en } from '../../../../shared/i18n/en';
import { iamUiConfig } from '../../../../shared/kit/kit.config';
import { GOOGLE_LOAD_TIMEOUT_MS, GoogleButtonComponent } from './google-button.component';

const CLIENT_ID = '1234-test.apps.googleusercontent.com';

/** The button as the sign-in page has it, beside something else to put the focus on - and a page that can lose it (a step that moves on). */
@Component({
  selector: 'app-test-google-host',
  imports: [GoogleButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '@if (shown()) {<app-google-button [text]="text()" [divider]="true" (credential)="credentials.push($event)" />}<button type="button" id="elsewhere">Elsewhere</button>',
})
class HostComponent {
  readonly text = signal<'signin_with' | 'signup_with' | 'continue_with'>('signin_with');
  readonly shown = signal(true);
  readonly credentials: string[] = [];
}

/** The sliver of Google Identity Services the IAM uses, faked: what it was asked, and the page's one callback. */
function fakeGoogle() {
  const fake = {
    callback: null as ((response: { credential: string }) => void) | null,
    initialize: vi.fn((config: { callback: (response: { credential: string }) => void }) => {
      fake.callback = config.callback;
    }),
    // Google draws its button in a frame of its own.
    renderButton: vi.fn((parent: HTMLElement, _options: Record<string, unknown>) => {
      const frame = document.createElement('iframe');
      frame.title = 'Sign in with Google Button';
      parent.appendChild(frame);
    }),
  };
  return fake;
}

/**
 * The IAM's Google button (sites/main/common/google-button): its own button first, drawn
 * with nothing asked of Google; Google's script only after a press, once a
 * page life; Google's own button in its place, said to a screen reader and
 * given the focus; the id token out as it came; and a short line, the rest
 * working, when Google cannot be reached. The owner's decision of 2026-10-14.
 */
describe('the Google button: the IAM’s own first, Google’s only after a press', () => {
  const theme = signal<'light' | 'dark'>('light');
  let fixture: ComponentFixture<HostComponent> | null = null;
  let http: HttpTestingController;

  function configure(values: Record<string, string> = en, language: 'en' | 'sk' = 'en'): void {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        language === 'en' ? provideTestTranslations(values) : provideTestTranslations(values, { language, english: en }),
        provideAnotokiUi(iamUiConfig),
        { provide: ThemeService, useValue: { resolved: theme } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
  }

  /** The page with the button, its config answered: Google on unless told otherwise. */
  async function draw(config: Partial<PublicConfig> = {}): Promise<HTMLElement> {
    fixture = TestBed.createComponent(HostComponent);
    document.body.appendChild(fixture.nativeElement);
    fixture.detectChanges();
    http.expectOne('/api/public/config').flush({ google_enabled: true, google_client_id: CLIENT_ID, ...config });
    await settled();
    return fixture.nativeElement as HTMLElement;
  }

  /** Every promise on its way has settled, and the page is drawn. */
  async function settled(): Promise<void> {
    await new Promise((done) => setTimeout(done));
    await fixture?.whenStable();
  }

  const ours = (host: HTMLElement) => host.querySelector<HTMLButtonElement>('button.app-google-button__button');
  const frame = (host: HTMLElement) => host.querySelector<HTMLElement>('.app-google-button__frame')!;
  const status = (host: HTMLElement) => host.querySelector('[role="status"]')?.textContent?.trim();
  const failure = (host: HTMLElement) => host.querySelector<HTMLElement>('.app-google-button__failed');
  const scripts = () => Array.from(document.querySelectorAll<HTMLScriptElement>('script')).filter((script) => script.src === GIS_SCRIPT);
  /** Every address anything on the page would fetch from. */
  const addresses = () => Array.from(document.querySelectorAll('[src], [href]')).map((element) => element.getAttribute('src') ?? element.getAttribute('href'));

  /** Google's script arrives - or does not. */
  async function scriptLoads(fake = fakeGoogle()): Promise<ReturnType<typeof fakeGoogle>> {
    window.google = { accounts: { id: fake } } as unknown as Window['google'];
    scripts().at(-1)!.dispatchEvent(new Event('load'));
    await settled();
    return fake;
  }
  async function scriptFails(): Promise<void> {
    scripts().at(-1)!.dispatchEvent(new Event('error'));
    await settled();
  }

  beforeEach(() => theme.set('light'));

  afterEach(() => {
    fixture?.destroy();
    fixture = null;
    http.verify();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.useRealTimers();
    delete window.google;
    document.head.querySelectorAll('script').forEach((script) => script.remove());
    document.body.innerHTML = '';
  });

  it('before a press: the IAM’s own button - a real button, Google’s "G" drawn on the page, the IAM’s words under an "or" - and nothing asked of Google', async () => {
    configure();
    // Every way a page could ask anything of anybody, watched from before the button is drawn.
    const load = vi.spyOn(TestBed.inject(GoogleSignInService), 'load');
    const fetched = vi.fn(() => Promise.reject(new Error('nothing is fetched here')));
    vi.stubGlobal('fetch', fetched);
    const opened = vi.spyOn(XMLHttpRequest.prototype, 'open');
    const host = await draw();

    const button = ours(host)!;
    expect(button.tagName).toBe('BUTTON');
    expect(button.getAttribute('type')).toBe('button');
    expect(button.textContent?.trim()).toBe('Sign in with Google');
    expect(host.querySelector('.divider-text')?.textContent?.trim()).toBe('or');
    // The mark is the page's own drawing in Google's four colours, hidden from a screen reader: nothing fetched for it.
    const mark = button.querySelector('svg')!;
    expect(mark.getAttribute('aria-hidden')).toBe('true');
    expect(Array.from(mark.querySelectorAll('path')).map((path) => path.getAttribute('fill'))).toEqual(['#EA4335', '#4285F4', '#FBBC05', '#34A853']);
    expect(button.querySelector('img')).toBeNull();

    // Nothing of Google's: no script, no frame, no address, no request of any kind - and the loader never asked.
    expect(load).not.toHaveBeenCalled();
    expect(scripts()).toEqual([]);
    expect(document.querySelectorAll('script, iframe, img, link').length).toBe(0);
    expect(addresses()).toEqual([]);
    expect(window.google).toBeUndefined();
    expect(fetched).not.toHaveBeenCalled();
    expect(opened).not.toHaveBeenCalled();
    // Google's place waits, hidden; nothing said yet, no failure.
    expect(frame(host).hidden).toBe(true);
    expect(status(host)).toBe('');
    expect(failure(host)).toBeNull();
  });

  it('the words: the IAM’s for each of Google’s texts, in the page’s language - from the database, so a rewording shows', async () => {
    configure();
    const host = await draw();
    fixture!.componentInstance.text.set('signup_with');
    await settled();
    expect(ours(host)?.textContent?.trim()).toBe('Sign up with Google');
    fixture!.componentInstance.text.set('continue_with');
    await settled();
    expect(ours(host)?.textContent?.trim()).toBe('Continue with Google');
    fixture?.destroy();
    TestBed.resetTestingModule();

    configure({ 'google.signIn': 'Prihlásiť sa cez Google', 'google.frame': 'Tlačidlo od Googlu', 'common.or': 'alebo' }, 'sk');
    const slovak = await draw();
    expect(ours(slovak)?.textContent?.trim()).toBe('Prihlásiť sa cez Google');
    expect(slovak.querySelector('.divider-text')?.textContent?.trim()).toBe('alebo');
    expect(frame(slovak).getAttribute('aria-label')).toBe('Tlačidlo od Googlu');
  });

  it('Google sign-in switched off: nothing at all - no button, no "or" leading to nothing, no script', async () => {
    configure();
    const host = await draw({ google_enabled: false, google_client_id: null });
    const button = host.querySelector('app-google-button')!;
    expect(button.children.length).toBe(0);
    expect(button.classList.contains('app-google-button--on')).toBe(false);
    expect(scripts()).toEqual([]);
  });

  it('a press: Google’s script, once a page life, then Google’s own button in the same place - said to a screen reader, the focus on its frame, the token out as it came', async () => {
    configure();
    const host = await draw();
    const button = ours(host)!;
    button.focus();
    button.click();
    await settled();

    // On its way: one script, Google's; the IAM's button busy and still there.
    expect(scripts().length).toBe(1);
    expect(scripts()[0].async).toBe(true);
    expect(ours(host)?.getAttribute('aria-busy')).toBe('true');
    expect(ours(host)?.querySelector('anotoki-spinner')).not.toBeNull();
    // A second press meanwhile asks for nothing more.
    ours(host)!.click();
    await settled();
    expect(scripts().length).toBe(1);

    const google = await scriptLoads();
    expect(google.initialize).toHaveBeenCalledTimes(1);
    expect(google.initialize).toHaveBeenCalledWith(expect.objectContaining({ client_id: CLIENT_ID, ux_mode: 'popup' }));
    expect(google.renderButton).toHaveBeenCalledTimes(1);
    const [parent, options] = google.renderButton.mock.calls[0];
    expect(frame(host).contains(parent)).toBe(true);
    // As wide as the IAM's button within Google's 200-400 px (no layout here: 320), in the page's language and theme.
    expect(options).toEqual({ type: 'standard', theme: 'outline', size: 'large', shape: 'pill', text: 'signin_with', logo_alignment: 'left', locale: 'en', width: 320 });

    // Google's button in the IAM's place: the IAM's gone, the frame shown and named, the focus on it, the news said.
    expect(ours(host)).toBeNull();
    expect(frame(host).hidden).toBe(false);
    expect(frame(host).querySelector('iframe')).not.toBeNull();
    expect(frame(host).getAttribute('role')).toBe('group');
    expect(frame(host).getAttribute('aria-label')).toBe('Google’s own button');
    expect(document.activeElement).toBe(frame(host));
    expect(status(host)).toBe('Google’s own button is ready: press it to choose your Google account.');
    expect(host.querySelector('.divider-text')).not.toBeNull();

    // A press on Google's button: its id token comes out as Google gave it.
    google.callback!({ credential: 'the-id-token' });
    expect(fixture!.componentInstance.credentials).toEqual(['the-id-token']);

    // The next page's button (the sign-up page, or the sign-in page's next step) uses the same script: no second one.
    const next = TestBed.createComponent(HostComponent);
    document.body.appendChild(next.nativeElement);
    next.detectChanges();
    await next.whenStable();
    expect(ours(next.nativeElement)).not.toBeNull();
    expect(google.renderButton).toHaveBeenCalledTimes(1);
    ours(next.nativeElement)!.click();
    await new Promise((done) => setTimeout(done));
    await next.whenStable();
    expect(scripts().length).toBe(1);
    expect(google.renderButton).toHaveBeenCalledTimes(2);
    next.destroy();
  });

  it('Google’s button follows the page: drawn again in the theme and language it changes to - and never again for nothing', async () => {
    configure();
    const host = await draw();
    expect(host.querySelector('app-google-button')?.classList.contains('app-google-button--dark')).toBe(false);
    theme.set('dark');
    await settled();
    // The IAM's button in Google's dark branding, still without Google.
    expect(host.querySelector('app-google-button')?.classList.contains('app-google-button--dark')).toBe(true);
    expect(scripts()).toEqual([]);

    ours(host)!.click();
    const google = await scriptLoads();
    expect(google.renderButton.mock.calls.at(-1)?.[1]).toEqual(expect.objectContaining({ theme: 'filled_black' }));
    fixture!.detectChanges();
    await settled();
    expect(google.renderButton).toHaveBeenCalledTimes(1);

    theme.set('light');
    await settled();
    expect(google.renderButton).toHaveBeenCalledTimes(2);
    expect(google.renderButton.mock.calls.at(-1)?.[1]).toEqual(expect.objectContaining({ theme: 'outline', locale: 'en' }));
    fixture!.componentInstance.text.set('continue_with');
    await settled();
    expect(google.renderButton).toHaveBeenCalledTimes(3);
    expect(google.renderButton.mock.calls.at(-1)?.[1]).toEqual(expect.objectContaining({ text: 'continue_with' }));
  });

  it('Google cannot be reached: the IAM’s button stays, a short line says so - an alert, and the button’s description - and another press tries again', async () => {
    configure();
    const host = await draw();
    ours(host)!.focus();
    ours(host)!.click();
    await scriptFails();

    // The failed script is gone; the IAM's button is back to itself, with the focus where it was.
    expect(scripts()).toEqual([]);
    const button = ours(host)!;
    expect(button.getAttribute('aria-busy')).toBeNull();
    expect(document.activeElement).toBe(button);
    const line = failure(host)!;
    expect(line.getAttribute('role')).toBe('alert');
    expect(line.textContent?.trim()).toBe('Google could not be reached: this browser may be blocking it, or you are offline.');
    expect(button.getAttribute('aria-describedby')).toBe(line.id);
    expect(frame(host).hidden).toBe(true);
    expect(status(host)).toBe('');
    // Nothing else of the page is touched: the other button is as it was.
    expect(host.querySelector<HTMLButtonElement>('#elsewhere')?.disabled).toBe(false);

    // Again: a fresh script, and this time Google comes.
    button.click();
    await settled();
    expect(scripts().length).toBe(1);
    expect(failure(host)).toBeNull();
    const google = await scriptLoads();
    expect(google.renderButton).toHaveBeenCalledTimes(1);
    expect(ours(host)).toBeNull();
  });

  it('a script that never answers: the same line after GOOGLE_LOAD_TIMEOUT_MS, and a later press waits for that script, not another', async () => {
    configure();
    const host = await draw();
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    ours(host)!.click();
    await vi.advanceTimersByTimeAsync(GOOGLE_LOAD_TIMEOUT_MS - 1);
    TestBed.tick();
    expect(failure(host)).toBeNull();
    expect(ours(host)?.getAttribute('aria-busy')).toBe('true');
    await vi.advanceTimersByTimeAsync(1);
    TestBed.tick();
    expect(failure(host)?.textContent?.trim()).toBe(en['google.failed']);
    expect(ours(host)?.getAttribute('aria-busy')).toBeNull();
    vi.useRealTimers();

    // The script arrives late: the next press draws Google's button from it, without asking for another.
    ours(host)!.click();
    const google = await scriptLoads();
    expect(scripts().length).toBe(1);
    expect(google.renderButton).toHaveBeenCalledTimes(1);
    expect(failure(host)).toBeNull();
  });

  it('a script that comes without Google’s sign-in in it (a download cut short, an extension’s stand-in): the same line, its element gone - and the next press fetches it again', async () => {
    configure();
    const host = await draw();
    ours(host)!.click();
    await settled();
    // It loads, and defines nothing of Google's.
    scripts().at(-1)!.dispatchEvent(new Event('load'));
    await settled();
    expect(failure(host)?.textContent?.trim()).toBe(en['google.failed']);
    expect(ours(host)).not.toBeNull();
    expect(scripts()).toEqual([]);

    // Again: fetched afresh, and this time Google's sign-in is in it.
    ours(host)!.click();
    await settled();
    expect(scripts().length).toBe(1);
    const google = await scriptLoads();
    expect(google.renderButton).toHaveBeenCalledTimes(1);
    expect(ours(host)).toBeNull();
    expect(failure(host)).toBeNull();
  });

  it('Google’s script there, but its button will not draw: the same line, and the IAM’s button stays', async () => {
    configure();
    const host = await draw();
    ours(host)!.click();
    const broken = fakeGoogle();
    broken.renderButton.mockImplementation(() => {
      throw new Error('GSI_LOGGER: the button could not be drawn');
    });
    await scriptLoads(broken);
    expect(ours(host)).not.toBeNull();
    expect(failure(host)?.textContent?.trim()).toBe(en['google.failed']);
    expect(frame(host).hidden).toBe(true);
    expect(status(host)).toBe('');
  });

  it('Google’s button that will not draw again for a new theme: the IAM’s button back with the line - and the live region no longer says Google’s is ready', async () => {
    configure();
    const host = await draw();
    ours(host)!.click();
    const google = await scriptLoads();
    expect(status(host)).toBe(en['google.ready']);

    google.renderButton.mockImplementation(() => {
      throw new Error('GSI_LOGGER: the button could not be drawn');
    });
    theme.set('dark');
    await settled();
    expect(ours(host)).not.toBeNull();
    expect(failure(host)?.textContent?.trim()).toBe(en['google.failed']);
    expect(frame(host).hidden).toBe(true);
    expect(status(host)).toBe('');
  });

  it('the focus is never taken from where somebody went while the script came; a button gone by then draws nothing, and answers for nothing', async () => {
    configure();
    const host = await draw();
    ours(host)!.focus();
    ours(host)!.click();
    const elsewhere = host.querySelector<HTMLButtonElement>('#elsewhere')!;
    elsewhere.focus();
    const google = await scriptLoads();
    expect(frame(host).hidden).toBe(false);
    expect(document.activeElement).toBe(elsewhere);

    // Gone, the page around it still there (a step that moves on): Google's one callback no longer answers for it.
    // Angular drops an emit on an output that is gone, so the button's own emit is what shows the callback released.
    const gone = fixture!.debugElement.query(By.directive(GoogleButtonComponent)).componentInstance as GoogleButtonComponent;
    const emitted = vi.spyOn(gone.credential, 'emit');
    fixture!.componentInstance.shown.set(false);
    await settled();
    expect(host.querySelector('app-google-button')).toBeNull();
    google.callback!({ credential: 'late' });
    await settled();
    expect(emitted).not.toHaveBeenCalled();
    expect(fixture!.componentInstance.credentials).toEqual([]);

    const again = TestBed.createComponent(HostComponent);
    document.body.appendChild(again.nativeElement);
    again.detectChanges();
    await again.whenStable();
    const before = google.renderButton.mock.calls.length;
    ours(again.nativeElement)!.click();
    again.destroy();
    await new Promise((done) => setTimeout(done));
    expect(google.renderButton.mock.calls.length).toBe(before);
    expect(again.componentInstance.credentials).toEqual([]);
  });
});
