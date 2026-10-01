import { DestroyRef, Injectable, computed, effect, inject, signal, untracked } from '@angular/core';
import { TranslationChanges } from '../api/models';
import { SessionService } from '../auth/session.service';

/** A language's row as it stands in its fields - text, the order too, until it is saved. */
export interface LanguageDraft {
  name: string;
  native_name: string;
  sort_order: string;
}

/** Where the drafts are kept for the tab: whose they are, the strings' cells and the languages' rows. */
export const LOCALIZATION_DRAFTS_KEY = 'anotoki-iam:localization-drafts';

/** The drafts as the tab keeps them. */
interface StoredDrafts {
  owner: string;
  strings: TranslationChanges;
  languages: Record<string, LanguageDraft>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** One language's row out of what was stored - or null when it is not one. */
function languageDraft(value: unknown): LanguageDraft | null {
  if (!isRecord(value)) {
    return null;
  }
  const { name, native_name, sort_order } = value;
  return typeof name === 'string' && typeof native_name === 'string' && typeof sort_order === 'string' ? { name, native_name, sort_order } : null;
}

/**
 * What the tab has kept, read as nothing is trusted to be: only a value of
 * exactly the shape written here is drafts - anything else (not JSON, another
 * shape, a text where a map should be) is none.
 */
function storedDrafts(text: string | null): StoredDrafts | null {
  if (text === null) {
    return null;
  }
  let parsed: unknown;
  try {
    parsed = JSON.parse(text);
  } catch {
    return null;
  }
  if (!isRecord(parsed) || typeof parsed['owner'] !== 'string' || parsed['owner'] === '' || !isRecord(parsed['strings']) || !isRecord(parsed['languages'])) {
    return null;
  }
  const strings: [string, Record<string, string>][] = [];
  for (const [key, cells] of Object.entries(parsed['strings'])) {
    if (!isRecord(cells) || !Object.values(cells).every((value) => typeof value === 'string')) {
      return null;
    }
    // fromEntries, so a name like "__proto__" is a key and nothing else.
    strings.push([key, Object.fromEntries(Object.entries(cells as Record<string, string>))]);
  }
  const languages: [string, LanguageDraft][] = [];
  for (const [code, value] of Object.entries(parsed['languages'])) {
    const draft = languageDraft(value);
    if (draft === null) {
      return null;
    }
    languages.push([code, draft]);
  }
  return { owner: parsed['owner'], strings: Object.fromEntries(strings), languages: Object.fromEntries(languages) };
}

/** sessionStorage that never throws: it can be missing, switched off or full, and a page is not broken over it. */
function readTab(): string | null {
  try {
    return globalThis.sessionStorage?.getItem(LOCALIZATION_DRAFTS_KEY) ?? null;
  } catch {
    return null;
  }
}

function writeTab(value: string | null): void {
  try {
    if (value === null) {
      globalThis.sessionStorage?.removeItem(LOCALIZATION_DRAFTS_KEY);
    } else {
      globalThis.sessionStorage?.setItem(LOCALIZATION_DRAFTS_KEY, value);
    }
  } catch {
    // Nothing to do: the drafts are in memory, and just will not survive a page load.
  }
}

/**
 * What is typed on the admin console's Translations and Languages pages and
 * not saved yet: the strings' changed cells, and the languages' changed rows.
 *
 * It is kept here, not in the pages, because a page can go with no question
 * asked: a session that ends - the idle limit, a sign-out in another tab, an
 * administrator ending it - sends the page to the sign-in form, and so does
 * the top bar's "Sign out", which does not ask about what is not saved. The
 * pages come and go; what was typed stays, and is in its boxes again when the
 * page is back.
 *
 * A reload in between is a page load, so the drafts are mirrored to
 * sessionStorage: for this tab only, and gone with it. They are the words of
 * pages and mails everybody reads - nothing secret is kept there.
 *
 * Whose they are: only ANOTHER person signing in lets go of them. A sign-out -
 * here, in another tab, or the session ending - keeps them, and signing in
 * again as the same person finds them.
 *
 * The one `beforeunload` question for both pages is asked here: closing the
 * tab loses the drafts, whichever page is showing - or none.
 *
 * It is made with the first of the two pages that is drawn, inside the admin
 * console - so after the session is known: who is signed in is settled when
 * it reads what the tab has kept.
 */
@Injectable({ providedIn: 'root' })
export class LocalizationDrafts {
  private readonly _session = inject(SessionService);

  /** The boxes of the Translations page that do not say what the IAM has: key -> language -> text. */
  readonly strings = signal<TranslationChanges>({});
  /** The rows of the Languages page whose fields do not say what is saved, by code. */
  readonly languages = signal<Record<string, LanguageDraft>>({});
  /** How many cells and rows are changed and not saved. */
  readonly count = computed(() => Object.values(this.strings()).reduce((cells, row) => cells + Object.keys(row).length, 0) + Object.keys(this.languages()).length);

  /** Who is signed in, as the drafts name their owner - or null for nobody (or nobody known yet). */
  private readonly _person = computed(() => {
    const user = this._session.user();
    return user ? String(user.id) : null;
  });
  /** Whose drafts these are: the person who typed them, signed in or not just now. */
  private readonly _owner = signal<string | null>(null);

  constructor() {
    const person = this._person();
    const stored = storedDrafts(readTab());
    // Kept for the person who is signed in - or for whoever signs in next,
    // when nobody is: the effect below lets go of them if it is somebody else
    // who comes.
    if (stored && (person === null || person === stored.owner)) {
      this._owner.set(stored.owner);
      this.strings.set(stored.strings);
      this.languages.set(stored.languages);
    } else {
      this._owner.set(person);
      // Broken, or somebody else's: no drafts, and not read again.
      writeTab(null);
    }

    // Whenever who is signed in changes: see settle().
    effect(() => {
      this._person();
      untracked(() => this.settle());
    });

    // Kept for the tab as the page next draws, whoever changed them.
    effect(() => this._mirror());

    // And as they are the moment the page goes - a reload may come before the
    // page has drawn again.
    const leaving = (): void => this._mirror();
    const warn = (event: BeforeUnloadEvent): void => {
      this._mirror();
      if (this.count() > 0) {
        event.preventDefault();
        event.returnValue = '';
      }
    };
    globalThis.addEventListener?.('beforeunload', warn);
    globalThis.addEventListener?.('pagehide', leaving);
    inject(DestroyRef).onDestroy(() => {
      globalThis.removeEventListener?.('beforeunload', warn);
      globalThis.removeEventListener?.('pagehide', leaving);
    });
  }

  /**
   * Every draft let go: given up, or somebody else's. Out of the tab's storage
   * at once, not when the page next draws: what was given up must not be there
   * for whoever comes next.
   */
  clear(): void {
    this.strings.set({});
    this.languages.set({});
    this._mirror();
  }

  /**
   * Whose the drafts are, against who is signed in now. Only somebody else
   * signing in lets go of what is here: signing out - here, in another tab, or
   * the IAM ending the session - keeps it all.
   *
   * It runs by itself whenever the session changes, but as an effect - when
   * the page next draws. A page made in that same moment would read another
   * person's drafts first, so the two pages call it before they read any.
   */
  settle(): void {
    const signedIn = this._person();
    if (signedIn !== null && signedIn !== this._owner()) {
      const hadOwner = this._owner() !== null;
      this._owner.set(signedIn);
      if (hadOwner) {
        this.clear();
      }
    }
  }

  /** The drafts as they are now, into the tab's storage - or nothing there, when there are none or nobody to keep them for. */
  private _mirror(): void {
    const owner = this._owner();
    const strings = this.strings();
    const languages = this.languages();
    writeTab(owner !== null && this.count() > 0 ? JSON.stringify({ owner, strings, languages } satisfies StoredDrafts) : null);
  }
}
