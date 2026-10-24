import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { AnotokiDialog } from '@anotoki/lib/ui/dialog';
import changelog from '../../../../public/changelog.json';
import lock from '../../../../package-lock.json';
import pkg from '../../../../package.json';
import { ChangelogDialogComponent } from '../changelog-dialog/changelog-dialog.component';
import { AccountUser } from '../../api/models';
import { SessionService } from '../auth/session.service';
import { iamInterceptor } from '../http/iam.interceptor';
import { CHANGELOG_URL, CHANGE_KINDS, Changelog, type ChangelogEntry, changelogEntries, changesIn } from './changelog.service';

/** A version's numbers ("1.0.0": [1, 0, 0]). */
const numbers = (version: string) => version.split('.').map(Number);
/** Negative when `a` is the older, positive when the newer. */
function compare(a: string, b: string): number {
  const [left, right] = [numbers(a), numbers(b)];
  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    if ((left[i] ?? 0) !== (right[i] ?? 0)) {
      return (left[i] ?? 0) - (right[i] ?? 0);
    }
  }
  return 0;
}

/** Slovak that speaks to the reader formally (Vy and its forms, the polite imperative): never the IAM's, whose Slovak says ty. */
const FORMAL = /(^|[^\p{L}])(vy|vás|vám|váš|vaš\p{L}*|vami|môžete|máte|pošlite|napíšte|skúste|prihláste|vyberte|stlačte|zmažte)(?![\p{L}])/iu;

const ENTRIES: ChangelogEntry[] = [
  {
    version: '1.2.0',
    date: '2026-11-01',
    changes: { added: ['A new page', 'A second thing'], fixed: ['A fixed thing'] },
    translations: { sk: { added: ['Nová stránka', 'Druhá vec'], fixed: ['Opravená vec'] } },
  },
  { version: '1.1.0', date: '2026-10-25', changes: { updated: ['A changed thing'] }, translations: { sk: { updated: [] } } },
];

@Component({ selector: 'app-nowhere', template: '', changeDetection: ChangeDetectionStrategy.OnPush })
class NowhereComponent {}

describe('public/changelog.json: the IAM’s versions', () => {
  const entries = changelog as unknown as ChangelogEntry[];

  it('every version three numbers, newest first, each dated - the newest frontend/package.json’s version (and package-lock.json’s two): one source, the one the footer shows', () => {
    const versions = entries.map((entry) => entry.version);
    expect(
      versions.filter((version) => !/^\d+\.\d+\.\d+$/.test(version)),
      'not three numbers',
    ).toEqual([]);
    for (let i = 1; i < versions.length; i++) {
      expect(compare(versions[i - 1], versions[i]), `${versions[i - 1]} before ${versions[i]}`).toBeGreaterThan(0);
    }
    expect(
      entries.filter((entry) => !/^\d{4}-\d\d-\d\d$/.test(entry.date)).map((entry) => entry.version),
      'dated YYYY-MM-DD',
    ).toEqual([]);
    for (let i = 1; i < entries.length; i++) {
      expect(entries[i - 1].date >= entries[i].date, `${entries[i - 1].version} is not dated before ${entries[i].version}`).toBe(true);
    }
    expect(versions[0]).toBe(pkg.version);
    const locked = lock as unknown as { version: string; packages: Record<string, { version?: string }> };
    expect([locked.version, locked.packages['']?.version], 'package-lock.json says the same version, twice').toEqual([pkg.version, pkg.version]);
    expect(changelogEntries(changelog).length, 'every entry read as one').toBe(entries.length);
    // The IAM starts at 1.0.0 (the owner's decision of 2026-10-19).
    expect(versions.at(-1)).toBe('1.0.0');
  });

  it('each version says what changed for people - in English, and whole in the IAM’s informal Slovak, with no address in any', () => {
    for (const entry of entries) {
      const sentences = CHANGE_KINDS.flatMap((kind) => entry.changes[kind] ?? []);
      expect(sentences.length, entry.version).toBeGreaterThan(0);
      const slovak = changesIn(entry, 'sk');
      expect(slovak.lang, `${entry.version}: a whole Slovak translation`).toBe('sk');
      const translated = CHANGE_KINDS.flatMap((kind) => slovak.changes[kind] ?? []);
      for (const sentence of [...sentences, ...translated]) {
        expect(sentence.trim(), entry.version).not.toBe('');
        expect(sentence, `${entry.version}: no address - ${sentence}`).not.toMatch(/https?:|www\.|@/);
      }
      for (const sentence of translated) {
        expect(sentence, `${entry.version}: the IAM's Slovak says ty - ${sentence}`).not.toMatch(FORMAL);
      }
    }
    // 1.0.0: what the IAM does for people - one account everywhere, two-factor and Google, the account page, deleting it.
    const first = entries.at(-1)!.changes.added?.join(' ') ?? '';
    for (const said of [/One anotoki account for every anotoki site/, /sign in once/, /two-factor/, /Google/, /account page/, /Delete your account/, /Feedback/]) {
      expect(first).toMatch(said);
    }
  });
});

describe('changesIn(): a version in the reader’s language', () => {
  it('its translation when it is whole - the language of the page, or its base - and the English otherwise', () => {
    expect(changesIn(ENTRIES[0], 'sk')).toEqual({ changes: ENTRIES[0].translations!['sk'], lang: 'sk' });
    expect(changesIn(ENTRIES[0], 'sk-sk').lang, 'a region is answered by its language').toBe('sk');
    expect(changesIn(ENTRIES[0], 'en')).toEqual({ changes: ENTRIES[0].changes, lang: 'en' });
    expect(changesIn(ENTRIES[0], 'de')).toEqual({ changes: ENTRIES[0].changes, lang: 'en' });
    // A translation short of a sentence is no translation: the English, which says it is English.
    expect(changesIn(ENTRIES[1], 'sk')).toEqual({ changes: ENTRIES[1].changes, lang: 'en' });
    const blank: ChangelogEntry = { ...ENTRIES[0], translations: { sk: { added: ['Nová stránka', ' '], fixed: ['Opravená vec'] } } };
    expect(changesIn(blank, 'sk').lang, 'a sentence with nothing in it').toBe('en');
  });

  it('reads the file as it is: what is not a version is left out', () => {
    expect(changelogEntries(null)).toEqual([]);
    expect(changelogEntries({ version: '1.0.0' })).toEqual([]);
    expect(
      changelogEntries([
        { version: '1.0.0', date: '2026-01-01', changes: { added: ['One', 3, null], fixed: 'Two' }, translations: { sk: { added: ['Jeden'] }, de: 'Eins' } },
        { version: 2, date: '2026-01-01', changes: {} },
        { version: '0.9.0', changes: {} },
        { version: '0.8.0', date: '2026-01-01' },
      ]),
    ).toEqual([{ version: '1.0.0', date: '2026-01-01', changes: { added: ['One'] }, translations: { sk: { added: ['Jeden'] } } }]);
  });
});

describe('Changelog: the version and What’s new', () => {
  let requests: HttpTestingController;
  const dialog = { open: vi.fn() };

  beforeEach(async () => {
    dialog.open.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideRouter([{ path: 'account/security', component: NowhereComponent }]),
        // Through the IAM's interceptor, as the app asks it.
        provideHttpClient(withInterceptors([iamInterceptor])),
        provideHttpClientTesting(),
        { provide: AnotokiDialog, useValue: dialog },
      ],
    });
    requests = TestBed.inject(HttpTestingController);
    TestBed.inject(SessionService).set({ id: 7, username: 'mira', email: 'mira@example.test' } as AccountUser);
    await TestBed.inject(Router).navigateByUrl('/account/security');
  });

  afterEach(() => requests.verify());

  it('asks for the list once a page load, beside index.html - and the newest version is the IAM’s', async () => {
    const changelogs = TestBed.inject(Changelog);
    expect(changelogs.current()).toBeNull();
    const first = changelogs.load();
    const second = changelogs.load();
    const asked = requests.expectOne(CHANGELOG_URL);
    expect(asked.request.method).toBe('GET');
    asked.flush(ENTRIES);
    await Promise.all([first, second]);
    expect(changelogs.current()).toBe('1.2.0');
    expect(changelogs.entries().map((entry) => entry.version)).toEqual(['1.2.0', '1.1.0']);
    await changelogs.load();
    requests.expectNone(CHANGELOG_URL);
  });

  it('a list that cannot be had leaves no version and no What’s new - moves no page, ends no session - and is asked for again next time', async () => {
    const changelogs = TestBed.inject(Changelog);
    const opened = changelogs.open();
    requests.expectOne(CHANGELOG_URL).flush('Not found', { status: 404, statusText: 'Not Found' });
    await opened;
    expect([changelogs.current(), dialog.open.mock.calls.length]).toEqual([null, 0]);
    expect([TestBed.inject(Router).url, TestBed.inject(SessionService).signedIn()]).toEqual(['/account/security', true]);

    const again = changelogs.load();
    requests.expectOne(CHANGELOG_URL).flush(ENTRIES);
    await again;
    expect(changelogs.current()).toBe('1.2.0');
  });

  it('What’s new: every version, newest first, in the kit’s dialog - its code loaded with the first press', async () => {
    const changelogs = TestBed.inject(Changelog);
    const opened = changelogs.open();
    requests.expectOne(CHANGELOG_URL).flush(ENTRIES);
    await opened;
    expect(dialog.open).toHaveBeenCalledTimes(1);
    const [component, options] = dialog.open.mock.calls[0];
    expect(component).toBe(ChangelogDialogComponent);
    expect(options).toEqual({ data: changelogEntries(ENTRIES), size: 'lg' });
  });
});
