import { DOCUMENT, Injectable, computed, effect, inject, signal } from '@angular/core';
import { isAdminArea } from '../auth/next-path';
import { SessionService } from '../auth/session.service';
import { LANGUAGES, Language } from '../api/models';
import { readStorage, writeStorage } from '../util/storage';
import { TranslationKey, en } from './en';
import { sk } from './sk';

const DICTIONARIES: Record<Language, Record<TranslationKey, string>> = { en, sk };

const LANGUAGE_KEY = 'anotoki-iam:lang';

/** What `Intl` is given for each language: British English writes dates the way the rest of Europe reads them. */
const LOCALES: Record<Language, string> = { en: 'en-GB', sk: 'sk-SK' };

export function isLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (LANGUAGES as readonly string[]).includes(value);
}

/**
 * Which language the pages are read in.
 *
 * Strongest first:
 *
 *   1. `?lang=` - the backend adds it from an application's `ui_locales`, so a
 *      Slovak site sends people to Slovak sign-in pages. The EN/SK switcher on
 *      the sign-in pages is the same kind of choice. Either holds for the rest
 *      of the visit.
 *   2. The signed-in account's own language.
 *   3. What this browser chose last time.
 *   4. The browser's preferred languages.
 *   5. English.
 *
 * The admin console is English whatever is chosen - `effectiveLanguage` says
 * so while the URL is under /admin, which also keeps `<html lang>` honest for
 * screen readers.
 */
@Injectable({ providedIn: 'root' })
export class TranslationService {
  private readonly _session = inject(SessionService);
  private readonly _document = inject(DOCUMENT);

  private readonly _override = signal<Language | null>(languageFromSearch(this._document.location?.search ?? ''));
  private readonly _stored = signal<Language | null>(storedLanguage());
  private readonly _browser = browserLanguage();
  private readonly _englishOnly = signal(isAdminArea(this._document.location?.pathname ?? '/'));

  /** The language chosen for the translated pages. */
  readonly language = computed<Language>(() => this._override() ?? this._session.user()?.language ?? this._stored() ?? this._browser ?? 'en');

  /** The language actually on screen: English in the admin console. */
  readonly effectiveLanguage = computed<Language>(() => (this._englishOnly() ? 'en' : this.language()));

  /** The `Intl` locale for dates and numbers in the language on screen. */
  readonly locale = computed(() => LOCALES[this.effectiveLanguage()]);

  constructor() {
    effect(() => {
      this._document.documentElement.lang = this.effectiveLanguage();
    });
  }

  /**
   * Called after every navigation, with the URL it arrived at: under /admin the
   * console is English, and a `?lang=` on the new URL wins for the visit.
   *
   * Told rather than listening to the Router itself - the Router builds the
   * title strategy, the title strategy needs this service, and this service
   * asking for the Router closed that circle (NG0200 at startup). The title
   * strategy already runs on every completed navigation, so it passes the URL on.
   */
  noteNavigation(url: string): void {
    this._englishOnly.set(isAdminArea(url));
    const fromUrl = languageFromSearch(url.includes('?') ? url.slice(url.indexOf('?')) : '');
    if (fromUrl) {
      this._override.set(fromUrl);
    }
  }

  /**
   * The string for a key, with `{placeholders}` filled in.
   *
   * A placeholder with no value is left as written, which is what lets a page
   * split a sentence around `{app}` and set the name in bold itself.
   */
  t(key: TranslationKey, params?: Record<string, string | number | null | undefined>): string {
    const template = DICTIONARIES[this.effectiveLanguage()][key] ?? en[key] ?? key;
    if (!params) {
      return template;
    }
    return template.replace(/\{(\w+)\}/g, (whole, name: string) => {
      const value = params[name];
      return value === undefined || value === null ? whole : String(value);
    });
  }

  /**
   * A choice made on this device: the switcher on the sign-in pages, or the
   * language select on the account page (which also saves it to the account).
   * It wins for the rest of the visit and is remembered for the next one.
   */
  choose(language: Language): void {
    this._override.set(language);
    this._stored.set(language);
    writeStorage(LANGUAGE_KEY, language);
  }
}

function languageFromSearch(search: string): Language | null {
  try {
    const value = new URLSearchParams(search).get('lang');
    return isLanguage(value) ? value : null;
  } catch {
    return null;
  }
}

function storedLanguage(): Language | null {
  const value = readStorage(LANGUAGE_KEY);
  return isLanguage(value) ? value : null;
}

/** The first of the browser's languages this site has, by its primary subtag (`sk-SK` is `sk`). */
function browserLanguage(): Language | null {
  const preferred = globalThis.navigator?.languages ?? [globalThis.navigator?.language ?? ''];
  for (const tag of preferred) {
    const primary = tag?.toLowerCase().split('-')[0];
    if (isLanguage(primary)) {
      return primary;
    }
  }
  return null;
}
