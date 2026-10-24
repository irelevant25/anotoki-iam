import { ApplicationRef } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTestTranslations } from '@anotoki/lib/translations/testing';
import { AnotokiDialog, AnotokiDialogRef } from '@anotoki/lib/ui/dialog';
import { AccountUser } from '../../api/models';
import { SessionService } from '../auth/session.service';
import type { ChangelogEntry } from '../changelog/changelog.service';
import { en } from '../i18n/en';
import { TranslationService } from '../i18n/translation.service';
import { ChangelogDialogComponent } from './changelog-dialog.component';

const ENTRIES: ChangelogEntry[] = [
  {
    version: '1.1.0',
    date: '2026-11-01',
    changes: { added: ['A new page', 'A second thing'], fixed: ['A fixed thing'] },
    translations: { sk: { added: ['Nová stránka', 'Druhá vec'], fixed: ['Opravená vec'] } },
  },
  // Its Slovak is short of a sentence: no translation - the English, saying so.
  { version: '1.0.0', date: '2026-10-19', changes: { updated: ['A changed thing', 'Another'] }, translations: { sk: { updated: ['Zmenená vec'] } } },
];

/** The frame's words in Slovak, as migration 024 (and 006's Close) wrote them. */
const SLOVAK = {
  'whatsNew.title': 'Čo je nové',
  'whatsNew.version': 'Verzia {version}',
  'whatsNew.latest': 'Najnovšia',
  'whatsNew.added': 'Pridané',
  'whatsNew.fixed': 'Opravené',
  'whatsNew.updated': 'Zmenené',
  'common.close': 'Zavrieť',
};

/** A day as the language writes it - the reader's own clock, noon: the same day in every time zone. */
const day = (locale: string, year: number, month: number, date: number) =>
  new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(year, month - 1, date, 12));

describe('What’s new: the IAM’s versions in the kit’s dialog, in the reader’s language', () => {
  let ref: AnotokiDialogRef | null = null;
  const stable = () => TestBed.inject(ApplicationRef).whenStable();
  /** The kit's dialog (AnotokiDialog puts it at the end of <body>). */
  const dialog = () => document.querySelector<HTMLElement>('anotoki-dialog')!;
  const texts = (selector: string) => [...dialog().querySelectorAll<HTMLElement>(selector)].map((element) => element.textContent?.replace(/\s+/g, ' ').trim());

  async function open(language: 'en' | 'sk'): Promise<void> {
    TestBed.configureTestingModule({
      providers: [language === 'sk' ? provideTestTranslations({ ...en, ...SLOVAK }, { language: 'sk', english: en }) : provideTestTranslations(en)],
    });
    ref = TestBed.inject(AnotokiDialog).open(ChangelogDialogComponent, { data: ENTRIES, size: 'lg' });
    await stable();
  }

  afterEach(async () => {
    ref?.close();
    ref = null;
    await stable();
  });

  it('in English: the heading, each version with its day, Latest on the newest, the kinds, the sentences - nothing marked', async () => {
    await open('en');
    const locale = TestBed.inject(TranslationService).locale();
    expect(texts('h2')).toEqual(["What's new"]);
    expect(texts('h3.version')).toEqual(['Version 1.1.0', 'Version 1.0.0']);
    expect(texts('anotoki-badge')).toEqual(['Latest']);
    expect(texts('.release-head .date')).toEqual([day(locale, 2026, 11, 1), day(locale, 2026, 10, 19)]);
    expect(texts('h4.kind')).toEqual(['Added', 'Fixed', 'Changed']);
    expect(texts('li')).toEqual(['A new page', 'A second thing', 'A fixed thing', 'A changed thing', 'Another']);
    expect(dialog().querySelectorAll('.release [lang]')).toHaveLength(0);
    expect(texts('.anotoki-dialog__footer button')).toEqual(['Close']);
  });

  it('in Slovak: the frame in Slovak, and a version’s own Slovak where it is whole - the English where it is not, its lists saying they are English', async () => {
    await open('sk');
    const locale = TestBed.inject(TranslationService).locale();
    expect(texts('h2')).toEqual(['Čo je nové']);
    expect(texts('h3.version')).toEqual(['Verzia 1.1.0', 'Verzia 1.0.0']);
    expect(texts('anotoki-badge')).toEqual(['Najnovšia']);
    expect(texts('.release-head .date')).toEqual([day(locale, 2026, 11, 1), day(locale, 2026, 10, 19)]);
    expect(texts('h4.kind')).toEqual(['Pridané', 'Opravené', 'Zmenené']);
    expect(texts('li')).toEqual(['Nová stránka', 'Druhá vec', 'Opravená vec', 'A changed thing', 'Another']);
    const lists = [...dialog().querySelectorAll('ul')];
    expect(lists.map((list) => list.getAttribute('lang'))).toEqual([null, null, 'en']);
    expect(texts('.anotoki-dialog__footer button')).toEqual(['Zavrieť']);
  });

  it('a signed-in person reads the days in the account’s own order', async () => {
    TestBed.configureTestingModule({ providers: [provideTestTranslations(en)] });
    TestBed.inject(SessionService).set({ id: 7, preferences: { theme: 'auto', date_format: 'ymd_dash', time_format: null } } as AccountUser);
    ref = TestBed.inject(AnotokiDialog).open(ChangelogDialogComponent, { data: ENTRIES, size: 'lg' });
    await stable();
    expect(texts('.release-head .date')).toEqual(['2026-11-01', '2026-10-19']);
  });

  it('follows the language on the page - English in the admin panel - and closes with its Close', async () => {
    await open('sk');
    expect(texts('h2')).toEqual(['Čo je nové']);
    TestBed.inject(TranslationService).noteAddress('/admin');
    await stable();
    expect(texts('h2'), 'the admin panel reads English').toEqual(["What's new"]);
    expect(texts('li')[0]).toBe('A new page');

    dialog().querySelector<HTMLButtonElement>('.anotoki-dialog__footer button')!.click();
    await stable();
    expect(document.querySelector('anotoki-dialog')).toBeNull();
    ref = null;
  });
});
