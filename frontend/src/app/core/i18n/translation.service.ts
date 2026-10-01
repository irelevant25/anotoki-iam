import { DOCUMENT, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { SiteLanguage, TranslationBundle } from '../api/models';
import { PublicApi } from '../api/public.api';
import { isAdminArea, isSessionArea } from '../auth/next-path';
import { SessionService } from '../auth/session.service';
import { readStorage, writeStorage } from '../util/storage';
import { TranslationKey, en } from './en';

/** What everything falls back to: the server merges English underneath every bundle, and the admin console is English. */
export const FALLBACK_LANGUAGE = 'en';

/** The language chosen on this device - the switcher, or the account page's select. */
const LANGUAGE_KEY = 'anotoki-iam:lang';
/** The last bundle, so a returning reader starts from their own language while the fresh one is on its way. */
const CACHE_KEY = 'anotoki-iam:lang-cache';

/** A language code as the database keeps it - the same expression as languageCodeOk() in PHP. */
const LANGUAGE_CODE = /^[a-z]{2}(-[a-z]{2})?$/;
/** A placeholder in a string - the same expression as the server's check. */
const PLACEHOLDER = /\{([A-Za-z][A-Za-z0-9_]*)\}/g;

/** What `Intl` is given: British English writes dates the way the rest of Europe reads them. Any other code is its own locale. */
const LOCALES: Record<string, string> = { en: 'en-GB', sk: 'sk-SK' };

/** How long a start with a cached bundle waits for the fresh one - a 304, usually. */
export const CACHE_WAIT_MS = 1000;
/** How long a first visit waits for its words before the page starts in the compiled English. */
export const FIRST_WAIT_MS = 3000;
/** How long a page in the account or admin area waits for a language it is switching to. */
export const SETTLE_WAIT_MS = 2000;

/** What fills a string's {placeholders}. */
export type TranslationParams = Record<string, string | number | null | undefined>;

/** The code, lower-cased, when it has the shape of one - anything else is nothing: never stored, never sent. */
export function languageCode(raw: unknown): string | null {
  const code = typeof raw === 'string' ? raw.toLowerCase() : '';
  return LANGUAGE_CODE.test(code) ? code : null;
}

/**
 * Every language the reader may want, in order. Each source is a tag - from
 * `?lang=`, an account, this device's stored choice, the browser - and gives
 * its lower-cased whole and then its first subtag (`sk-SK` asks for `sk-sk`,
 * then `sk`), which is how the server reads an application's `ui_locales`.
 * English closes the list.
 */
export function languageCandidates(sources: readonly unknown[]): string[] {
  const candidates: string[] = [];
  for (const code of [...sources.flatMap(languageForms), FALLBACK_LANGUAGE]) {
    if (!candidates.includes(code)) {
      candidates.push(code);
    }
  }
  return candidates;
}

/** What a tag can ask for: its lower-cased whole, then its first subtag - each only when it has the shape of a code. */
function languageForms(tag: unknown): string[] {
  if (typeof tag !== 'string') {
    return [];
  }
  const whole = languageCode(tag);
  const first = languageCode(tag.split('-')[0]);
  return [whole, first].filter((code, index, forms): code is string => code !== null && forms.indexOf(code) === index);
}

/**
 * The first candidate that is on offer, else English. A wish nobody offers -
 * an account whose language was switched off, say - is skipped, not turned
 * into English: the next source still has its say.
 */
export function pickLanguage(candidates: readonly string[], offered: readonly string[]): string {
  return candidates.find((candidate) => offered.includes(candidate)) ?? FALLBACK_LANGUAGE;
}

/** The words in memory: the language on the translated pages, and English for the admin console. */
interface Words {
  values: ReadonlyMap<string, string>;
  english: ReadonlyMap<string, string>;
}

/** The compiled English, as a map - so `has('constructor')` is false, as it is for a bundle. */
const COMPILED: ReadonlyMap<string, string> = new Map(Object.entries(en));

/**
 * The site's own words: which language the pages are read in, and the strings
 * for it. They live in the database (the admin console edits them) and arrive
 * as one bundle - `GET /api/translations/{code}`.
 *
 * Which language, strongest first:
 *
 *   1. `?lang=` - the backend adds it from an application's `ui_locales`, so a
 *      Slovak site sends people to Slovak sign-in pages. The switcher on the
 *      sign-in pages is the same kind of choice. Either holds for the visit.
 *   2. The signed-in account's own language.
 *   3. What this device chose last time.
 *   4. The browser's preferred languages.
 *   5. English.
 *
 * ...each of them only while it is on offer. `language` is what is on the page,
 * and it changes only once that language's strings are in memory: the wish
 * moves first (somebody signs in, a `?lang=` arrives), the bundle is fetched,
 * and only then does the page switch - it is never in a language it has no
 * words for.
 *
 * With a bundle in memory, the bundle is what is shown: a key it lacks comes
 * back as the key itself, on the page, and warns once - the owner wants to see
 * which string is missing. The compiled English (`en.ts`) is used only while
 * there is no bundle at all: between an upload and "Apply pending" the
 * database has no `languages` table yet and the route answers 503, and the
 * sign-in page must still be readable or nobody could sign in to press Apply.
 *
 * The admin console is English whatever is chosen - `effectiveLanguage` says
 * so while the address is under /admin, which also keeps `<html lang>` honest
 * for screen readers.
 *
 * This service never injects the Router and starts no request while it is
 * being built: the Router builds the title strategy, the strategy needs this
 * service, and a request goes through the interceptor, which asks for the
 * Router - the circle closes (NG0200 at start-up). Requests begin in init(),
 * and the title strategy says where the page is - noteNavigation().
 */
@Injectable({ providedIn: 'root' })
export class TranslationService {
  private readonly _api = inject(PublicApi);
  private readonly _session = inject(SessionService);
  private readonly _document = inject(DOCUMENT);

  /** `?lang=`, or a choice made on the page. It holds for the visit. */
  private readonly _override = signal<string | null>(tagFromSearch(this._document.location?.search ?? ''));
  private readonly _stored = signal<string | null>(languageCode(readStorage(LANGUAGE_KEY)));
  private readonly _browser = browserTags(this._document.defaultView?.navigator);
  private readonly _englishOnly = signal(isAdminArea(this._document.location?.pathname ?? '/'));

  private readonly _language = signal(FALLBACK_LANGUAGE);
  private readonly _languages = signal<SiteLanguage[]>([]);
  /** Null: no bundle in memory - the pages read the compiled English. */
  private readonly _words = signal<Words | null>(null);

  /**
   * The language of the translated pages. A plain signal: it is set only when
   * a bundle is put in force, never by itself when the account or the address
   * changes.
   */
  readonly language = this._language.asReadonly();

  /** The languages on offer, in the order the admin console set. Empty while no bundle has arrived. */
  readonly languages = this._languages.asReadonly();

  /** The language actually on screen: English in the admin console. */
  readonly effectiveLanguage = computed(() => (this._englishOnly() ? FALLBACK_LANGUAGE : this._language()));

  /** The `Intl` locale for dates and numbers in the language on screen. */
  readonly locale = computed(() => {
    const code = this.effectiveLanguage();
    return Object.hasOwn(LOCALES, code) ? LOCALES[code] : code;
  });

  private readonly _candidates = computed(() => languageCandidates([this._override(), this._session.user()?.language, this._stored(), ...this._browser]));

  /**
   * The language the reader should have: the candidates settled against what
   * is on offer. While nothing is known to be on offer (no bundle yet), the
   * first wish - the server answers in English when it does not have it, and
   * says what it does have.
   */
  private readonly _wanted = computed(() => {
    const candidates = this._candidates();
    const offered = this._languages();
    return offered.length
      ? pickLanguage(
          candidates,
          offered.map((language) => language.code),
        )
      : candidates[0];
  });

  /** The strings on screen: English in the admin console, the compiled English while there is no bundle. */
  private readonly _strings = computed<ReadonlyMap<string, string>>(() => {
    const words = this._words();
    return words ? (this._englishOnly() ? words.english : words.values) : COMPILED;
  });

  /** One request per language at a time: whoever asks while it is out shares it. */
  private readonly _requests = new Map<string, Promise<TranslationBundle | null>>();
  /** The wanted language being brought onto the page, so the effect, the guard and init() share one attempt. */
  private _bringing: { code: string; promise: Promise<void> } | null = null;
  /** The code asked for last. An answer is dropped only when a different code was asked for after it. */
  private _asked: string | null = null;
  /** The code whose load failed last, so a guard does not ask for it again on its own. */
  private _failed: string | null = null;
  /**
   * The bundle on the page is an older one - the cached one, or the one from
   * before reload() - and the request that was to refresh it failed: a release
   * or the admin console may have changed strings since, so it is asked for
   * again on the next navigation. The next answer clears it.
   */
  private _stale = false;
  /** The load init() stopped waiting for: the page has started on the compiled English, and no guard waits for it again. */
  private _leftBehind: Promise<void> | null = null;
  /** The bundle the page reads now, as it came - what the next one replaces. */
  private _shown: TranslationBundle | null = null;
  /**
   * The way back: the one bundle the last choice took off the page. choose()
   * takes it from memory when it is asked for that language again, so a
   * choice that is then refused - the account page's, when the account does
   * not take it - can be undone without the server, which may be why it was
   * refused. Only a choice leaves one: whatever else puts words on the page
   * (the wish moving by itself, reload()) closes it, so a language chosen for
   * the first time is always asked for.
   */
  private _replaced: TranslationBundle | null = null;
  /** Keys already complained about, so one missing string is one warning. */
  private readonly _warned = new Set<string>();

  private _init: Promise<void> | null = null;
  /** init() has reached its own request: from here the effect may follow the wanted language. */
  private _started = false;
  /** Not on the first navigation, which ends a moment after init() asked. */
  private _askAgainOnNavigation = false;

  constructor() {
    // Honest for a screen reader: the admin console says `en` whatever the person reads.
    effect(() => {
      this._document.documentElement.lang = this.effectiveLanguage();
    });

    // Follows the wanted language: somebody signs in, a `?lang=` arrives with a
    // navigation, the account page saves another language. The bundle first,
    // `language` after. It reads only the wish - everything else it touches is
    // untracked, so putting a bundle in force does not run it again.
    effect(() => {
      const wanted = this._wanted();
      untracked(() => {
        if (this._started && !this._inForce(wanted)) {
          void this._bring();
        }
      });
    });
  }

  // ── Reading ────────────────────────────────────────────────────────────────

  /**
   * The string for a key, with `{placeholders}` filled in - in one pass, so a
   * parameter's own text is never scanned again.
   *
   * A placeholder with no value is left as written, which is what lets a page
   * split a sentence around `{app}` and set the name in bold itself.
   *
   * With a bundle in memory, a key it has no string for comes back as the key.
   */
  t(key: TranslationKey, params?: TranslationParams): string {
    const value = this._strings().get(key);
    return value === undefined ? this._missing(key) : fill(value, params);
  }

  // ── Choosing ───────────────────────────────────────────────────────────────

  /**
   * A choice made on this device: the switcher on the sign-in pages, or the
   * language select on the account page (which also saves it to the account).
   * It wins for the rest of the visit and is remembered for the next one.
   *
   * The bundle first; then, in the same tick, it is put in force, the choice
   * is set and stored - so the effect above finds the wish already on the page
   * and asks for nothing. Never rejects. True when that language is on the
   * page; false when its strings could not be had (or it is no longer on
   * offer), and the page stays as it was.
   *
   * A language is asked for every time it is chosen - English too, though it
   * came beside the bundle on the page: a server that cannot be reached is
   * then found before the page switches, not after, when the account can no
   * longer be told. The one exception is the way back: the language the last
   * choice took off the page is still in memory and needs no request, so a
   * choice can always be undone - the account page does that when the account
   * refuses it, which may be because the server has gone.
   */
  async choose(code: string): Promise<boolean> {
    const wanted = languageCode(code);
    if (!wanted) {
      return false;
    }
    // Said even when it needs no request: an answer still on its way for
    // another language is then one nobody wants any more.
    this._asked = wanted;
    if (!this._inForce(wanted)) {
      const bundle = this._wayBack(wanted) ?? (await this._request(wanted));
      if (this._asked !== wanted) {
        return false;
      }
      if (!bundle || bundle.language !== wanted) {
        // Switched off a moment ago: the server answered in English. The page
        // stays in its language; the switcher stops offering the one that went.
        if (bundle?.languages.some((language) => language.code === this._language())) {
          this._languages.set(bundle.languages);
        }
        return false;
      }
      this._use(bundle, 'choice');
    }
    this._override.set(wanted);
    this._stored.set(wanted);
    writeStorage(LANGUAGE_KEY, wanted);
    return true;
  }

  /**
   * The language a new account is given: the reader's wish - the same
   * candidates the page follows - settled against what the server says it
   * offers (`languages` of `GET /api/public/config`, which the pages that make
   * an account hold).
   *
   * With a bundle on the page this is `language()`. Without one it is not: the
   * page reads the compiled English, `language()` says `en`, and an account
   * made then - between an upload and "Apply pending", or while the words
   * could not be loaded - would be English for good, though it was asked for
   * in Slovak.
   */
  preferred(offered: readonly string[]): string {
    return offered.length ? pickLanguage(this._candidates(), offered) : this._language();
  }

  /**
   * Called after every navigation, with the URL it arrived at: under /admin the
   * console is English, and a `?lang=` on the new URL wins for the visit.
   *
   * Told rather than listening to the Router itself - see the class comment.
   * The title strategy already runs on every completed navigation, so it
   * passes the URL on.
   *
   * This is also when the words are asked for again - once per navigation -
   * while the page does not read what it should: no bundle is in memory (the
   * first page somebody opens after "Apply pending" is read from the
   * database), the wanted language is not the one on the page (somebody signed
   * in and the bundle of their language did not come), or the bundle on the
   * page is the cached one and its refresh failed. (Not on the first navigation
   * of a visit, which ends a moment after init() asked.)
   */
  noteNavigation(url: string): void {
    this._englishOnly.set(isAdminArea(url));

    const query = url.split('#')[0];
    const tag = tagFromSearch(query.includes('?') ? query.slice(query.indexOf('?')) : '');
    // A language nobody offers does not unseat a choice already made.
    if (tag && (!this._languages().length || languageForms(tag).some((code) => this._offers(code)))) {
      this._override.set(tag);
    }

    if (this._started && this._askAgainOnNavigation) {
      // A load that failed is worth one more try per navigation, and this is
      // the only place that sees every navigation: the guard's settled() runs
      // on the way into the account or admin area, not between the pages of
      // one. No bundle at all is the second case too - nothing is in force.
      this._failed = null;
      if (this._stale || !this._inForce(this._wanted())) {
        void this._bring();
      }
    }
    this._askAgainOnNavigation = true;
  }

  // ── Starting ───────────────────────────────────────────────────────────────

  /**
   * Once as the site starts - returned from the app initializer, so the first
   * page is drawn with its words. Never rejects.
   *
   * In the account and admin areas the account is asked for first (the guard
   * reuses the answer), so its language is known before the one request for
   * the words. Then: the cached bundle is put in force when there is one, the
   * fresh one is asked for all the same, and the start waits for it - a second
   * with a cache, three without. After that the page starts on what it has
   * (the cache, or the compiled English), and the bundle is put in force when
   * it arrives.
   */
  init(): Promise<void> {
    this._init ??= this._start().catch((error: unknown) => console.error('[i18n] the language could not be set up', error));
    return this._init;
  }

  /**
   * Resolves when the wanted language is on the page, or its load has failed -
   * never rejects, and never later than two seconds. The guard of the account
   * and admin areas awaits it, so somebody who has just signed in does not see
   * their account page in the sign-in page's language first.
   */
  settled(): Promise<void> {
    const wanted = this._wanted();
    if (!this._started || this._inForce(wanted) || this._failed === wanted) {
      return Promise.resolve();
    }
    const bringing = this._bring();
    // The start already waited its whole time for this one.
    return bringing === this._leftBehind ? Promise.resolve() : Promise.race([bringing, wait(SETTLE_WAIT_MS)]);
  }

  /**
   * The words once more, from the server. The admin console calls it after it
   * has saved strings or languages, so that its own shell, the sign-in pages
   * and their switcher follow the change without the site being loaded again.
   * Never rejects.
   *
   * It asks for the wanted language, as every load does, and settles against
   * the list of languages that comes with the answer: the language on the page
   * may just have been switched off (the answer is then in English), and one
   * just added may be what the reader wanted all along. What comes is put in
   * force and kept as the cache, and the way back is closed - it holds words
   * from before the change. When nothing comes the page keeps what it has,
   * and the next navigation asks again.
   *
   * One load per code still: a request already on its way was sent before the
   * change this is called for, so it is let to land, and a new one follows.
   */
  async reload(): Promise<void> {
    try {
      if (!this._started) {
        // Nothing has been read yet: the start asks, and what it gets is fresh.
        await this.init();
        return;
      }
      this._replaced = null;
      await Promise.all([this._bringing?.promise, ...this._requests.values()]);
      await this._bring();
      // The list that came may have moved the wish. The effect follows it
      // too; this shares its attempt, so whoever waits for reload() waits
      // for the page to be where it settles.
      const wanted = this._wanted();
      if (!this._inForce(wanted) && this._failed !== wanted) {
        await this._bring();
      }
    } catch (error) {
      console.error('[i18n] the words could not be read again', error);
    }
  }

  private async _start(): Promise<void> {
    const path = this._document.location?.pathname ?? '/';
    // `/` is the account area too: it redirects there.
    if (path === '/' || isSessionArea(path)) {
      try {
        await firstValueFrom(this._session.ensure());
      } catch {
        // The server is down or not set up: the guard asks again, and says so.
      }
    }

    const cached = this._cachedBundle();
    if (cached) {
      this._use(cached, 'cache');
    }

    this._started = true;
    const bringing = this._bring();
    let arrived = false;
    // With a cache the page may start from it, but not at once: a release adds
    // strings, and a returning reader would see their keys. The fresh bundle is
    // a 304 most of the time - worth a second's wait.
    await Promise.race([bringing.then(() => (arrived = true)), wait(cached ? CACHE_WAIT_MS : FIRST_WAIT_MS)]);
    if (!arrived) {
      this._leftBehind = bringing;
    }
  }

  // ── Loading ────────────────────────────────────────────────────────────────

  /** Whether that language's bundle is what the page reads now. */
  private _inForce(code: string): boolean {
    return this._words() !== null && this._language() === code;
  }

  private _offers(code: string): boolean {
    return this._languages().some((language) => language.code === code);
  }

  /**
   * The wish went back, while `code` was on its way, to the language already
   * on the page - which needs no request, so nothing else says the answer is
   * no longer wanted.
   */
  private _overtaken(code: string): boolean {
    const wanted = this._wanted();
    return wanted !== code && this._inForce(wanted);
  }

  /** Brings the wanted language onto the page. One attempt per code at a time, shared by everybody who asks. */
  private _bring(): Promise<void> {
    const code = this._wanted();
    if (this._bringing?.code === code) {
      return this._bringing.promise;
    }
    const promise: Promise<void> = this._follow(code).finally(() => {
      if (this._bringing?.promise === promise) {
        this._bringing = null;
      }
    });
    this._bringing = { code, promise };
    return promise;
  }

  /**
   * The bundle for the language the candidates settle on, put in force. The
   * list of offered languages arrives with the bundle itself, so: ask for
   * `first`; when another language answers, settle against the list that came;
   * that is nearly always the language of the answer - the server answers a
   * regional code (`sk-sk`, a Slovak browser's first wish) by its base
   * language - and the answer is used as it is. Only when it is yet another
   * (the language asked for is unknown or switched off and the next wish is
   * on offer, or an older server answered the regional code in English) is
   * that bundle fetched too. Two requests at most, and only on a first visit
   * or just after a language went.
   */
  private async _follow(first: string): Promise<void> {
    this._asked = first;
    const answer = await this._request(first);
    if (this._asked !== first || this._overtaken(first)) {
      return;
    }
    // A bundle is in force while its own language is being asked for only when
    // it is an older one: the cache's, or the one reload() is to replace.
    this._stale = !answer && this._inForce(first);
    if (!answer) {
      this._failed = first;
      return;
    }
    if (answer.language === first) {
      this._use(answer);
      return;
    }
    const settled = pickLanguage(
      this._candidates(),
      answer.languages.map((language) => language.code),
    );
    if (settled === answer.language) {
      this._use(answer);
      return;
    }
    this._asked = settled;
    const second = await this._request(settled);
    // The wish as it stands now, judged against the list that came with the
    // first answer - not through _wanted(), which still reads the list of the
    // bundle on the page. When that is a cached bundle in a language switched
    // off since, the old list would call this answer overtaken by the very
    // language that went, and the cache would never be replaced.
    const now = pickLanguage(
      this._candidates(),
      answer.languages.map((language) => language.code),
    );
    if (this._asked !== settled || (now !== settled && this._inForce(now))) {
      return;
    }
    if (!second) {
      this._failed = settled;
    }
    this._use(second ?? answer);
  }

  /** One bundle from the server, checked - or null: out of reach, refused, or not what a bundle is. Shared per code. */
  private _request(code: string): Promise<TranslationBundle | null> {
    let request = this._requests.get(code);
    if (!request) {
      request = firstValueFrom(this._api.translations(code))
        .then(bundleFrom)
        // Offline, a 503 before "Apply pending", the API down. The cache - or
        // the compiled English - is what the page shows then.
        .catch(() => null)
        .finally(() => this._requests.delete(code));
      this._requests.set(code, request);
    }
    return request;
  }

  /**
   * Puts a checked bundle in force - strings first, then the language they are
   * in - and, unless it is the cache's own, keeps it as the cache.
   *
   * `from` says what brought it: a `choice` keeps the bundle of the language
   * it takes off the page as the way back; the `server` answering the wish, or
   * the `cache` at the start, leaves none.
   */
  private _use(bundle: TranslationBundle, from: 'choice' | 'server' | 'cache' = 'server'): void {
    if (from !== 'choice') {
      this._replaced = null;
    } else if (this._shown && this._shown.language !== bundle.language) {
      this._replaced = this._shown;
    }
    this._shown = bundle;

    const values = new Map(Object.entries(bundle.values));
    const english = bundle.language === FALLBACK_LANGUAGE ? values : new Map(Object.entries(bundle.english ?? {}));
    this._words.set({ values, english: english.size ? english : COMPILED });
    this._languages.set(bundle.languages);
    this._language.set(bundle.language);
    if (this._failed === bundle.language) {
      this._failed = null;
    }
    if (from !== 'cache') {
      writeStorage(CACHE_KEY, JSON.stringify(bundle));
    }
  }

  /**
   * The bundle the last choice took off the page, when it is in that language
   * and the language is still on offer - with the list of languages as it is
   * known now, which is never older than the one it came with. Null for any
   * other language: that one is asked for.
   */
  private _wayBack(code: string): TranslationBundle | null {
    const replaced = this._replaced;
    return replaced?.language === code && this._offers(code) ? { ...replaced, languages: this._languages() } : null;
  }

  /**
   * The cached bundle, only when it is one - it parses, has the shape - and is
   * in the language the candidates settle on now. A corrupt one is removed; one
   * in another language is left for the visit it belongs to.
   */
  private _cachedBundle(): TranslationBundle | null {
    const raw = readStorage(CACHE_KEY);
    if (raw === null) {
      return null;
    }
    let bundle: TranslationBundle | null = null;
    try {
      bundle = bundleFrom(JSON.parse(raw));
    } catch {
      bundle = null;
    }
    if (!bundle) {
      writeStorage(CACHE_KEY, null);
      return null;
    }
    const settled = pickLanguage(
      this._candidates(),
      bundle.languages.map((language) => language.code),
    );
    return bundle.language === settled ? bundle : null;
  }

  private _missing(key: string): string {
    if (!this._warned.has(key)) {
      this._warned.add(key);
      console.warn(`[i18n] no string for '${key}'`);
    }
    return key;
  }
}

function fill(value: string, params?: TranslationParams): string {
  if (!params) {
    return value;
  }
  return value.replace(PLACEHOLDER, (whole, name: string) => {
    const given = Object.hasOwn(params, name) ? params[name] : undefined;
    return given === undefined || given === null ? whole : String(given);
  });
}

function wait(milliseconds: number): Promise<void> {
  return new Promise((done) => setTimeout(done, milliseconds));
}

/**
 * `?lang=`, as the most it can ask for: its whole when that has the shape of a
 * code (`sk-sk`, whose first subtag the candidates still read), else its first
 * subtag, else nothing.
 */
function tagFromSearch(search: string): string | null {
  try {
    return languageForms(new URLSearchParams(search).get('lang'))[0] ?? null;
  } catch {
    return null;
  }
}

/** The browser's preferred languages, most wanted first. */
function browserTags(navigator: Navigator | undefined): string[] {
  const tags = navigator?.languages?.length ? navigator.languages : [navigator?.language];
  return tags.filter((tag): tag is string => typeof tag === 'string');
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** A flat {key: text} map, copied - or null when it is anything else. */
function stringsFrom(raw: unknown): Record<string, string> | null {
  if (!isPlainObject(raw)) {
    return null;
  }
  const strings: Record<string, string> = {};
  for (const [key, value] of Object.entries(raw)) {
    if (typeof value !== 'string') {
      return null;
    }
    strings[key] = value;
  }
  return strings;
}

/**
 * A bundle, from the server or from the cache, only when it is one: a language
 * code, the offered languages (strings, and the bundle's own among them), a
 * flat map of strings that is not empty - and English beside it for every
 * other language. A bundle with no strings is no bundle: it would put a key
 * where every word should be.
 */
function bundleFrom(raw: unknown): TranslationBundle | null {
  if (!isPlainObject(raw) || !Array.isArray(raw['languages'])) {
    return null;
  }
  const language = raw['language'];
  if (typeof language !== 'string' || !LANGUAGE_CODE.test(language)) {
    return null;
  }
  const languages: SiteLanguage[] = [];
  for (const entry of raw['languages']) {
    if (!isPlainObject(entry)) {
      return null;
    }
    const { code, name, native_name } = entry;
    if (typeof code !== 'string' || !LANGUAGE_CODE.test(code) || typeof name !== 'string' || typeof native_name !== 'string') {
      return null;
    }
    languages.push({ code, name, native_name });
  }
  const values = stringsFrom(raw['values']);
  if (!values || !Object.keys(values).length || !languages.some((entry) => entry.code === language)) {
    return null;
  }
  if (language === FALLBACK_LANGUAGE) {
    return { language, languages, values };
  }
  const english = stringsFrom(raw['english']);
  return english ? { language, languages, values, english } : null;
}
