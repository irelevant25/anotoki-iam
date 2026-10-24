import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { AnotokiDialog } from '@anotoki/lib/ui/dialog';
import { firstValueFrom } from 'rxjs';

/** What one version changed, by kind: each a sentence about something people notice. */
export interface ChangelogChanges {
  added?: string[];
  fixed?: string[];
  updated?: string[];
}

/**
 * One version in public/changelog.json, newest first - the anotoki sites'
 * changelog format (a version, its date, what it changed in English), and
 * beside it the same lists in another language (`translations.sk`: the IAM's
 * informal Slovak), which a reader of that language is shown when it is whole.
 */
export interface ChangelogEntry {
  /** MAJOR.MINOR.PATCH: big changes, new features, fixes ("1.0.0"). */
  version: string;
  /** YYYY-MM-DD: a day, not a moment. */
  date: string;
  /** In English: what every language falls back to. */
  changes: ChangelogChanges;
  /** The same lists in another language, by its code. */
  translations?: Record<string, ChangelogChanges>;
}

/** The kinds of change, in the order a version lists them. */
export const CHANGE_KINDS = ['added', 'fixed', 'updated'] as const;
export type ChangeKind = (typeof CHANGE_KINDS)[number];

/** Where the list is: shipped with the pages (public/), beside index.html - never asked of the API. */
export const CHANGELOG_URL = 'changelog.json';

/**
 * The IAM's versions and what each changed (public/changelog.json; the
 * owner's decision of 2026-10-19 - the IAM starts at 1.0.0). The newest
 * entry's version is the IAM's version - one source: what the family's footer
 * shows under every page and in the admin panel ("v1.0.0", which a screen
 * reader hears as "Version 1.0.0"; `current()`), and frontend/package.json's
 * own version is held to it by a spec. What's new (`open()`) lists every
 * version in the kit's dialog (shared/changelog-dialog, loaded with the first
 * press: no first load carries it), in the reader's language.
 *
 * It opens when asked - by the What's new of the family's footer
 * (<anotoki-site-footer>'s `(whatsNew)`, in each frame: sites/main/auth/auth-layout,
 * sites/main/legal/legal-layout and the account and admin layouts) - and never by
 * itself: nothing about what a browser has seen is kept, so nothing new is
 * stored in it (the Privacy page lists every item the IAM keeps there). The
 * list is asked for once a page load, as the IAM starts (app.config.ts, never
 * waited for), so the footer has its version when the first page is drawn; a
 * list that cannot be had (a broken upload) leaves no version and no What's
 * new - the footers show neither - and is asked for again next time. Its
 * request is no request of the person's: not the API's, so the IAM's
 * interceptor moves no page for its failure.
 */
@Injectable({ providedIn: 'root' })
export class Changelog {
  private readonly _http = inject(HttpClient);
  private readonly _dialog = inject(AnotokiDialog);
  private _loading: Promise<void> | null = null;

  /** Every version, newest first; none until the list came. */
  readonly entries = signal<readonly ChangelogEntry[]>([]);
  /** The IAM's version - "1.0.0" - or null until the list came. */
  readonly current = computed(() => this.entries()[0]?.version ?? null);

  /** The list, once a page load. Never rejects. */
  load(): Promise<void> {
    return (this._loading ??= this._fetch());
  }

  /** What's new: every version, newest first, in the kit's dialog - nothing when there is no list. */
  async open(): Promise<void> {
    await this.load();
    const entries = this.entries();
    if (entries.length === 0) {
      return;
    }
    const { ChangelogDialogComponent } = await import('../changelog-dialog/changelog-dialog.component');
    this._dialog.open(ChangelogDialogComponent, { data: entries, size: 'lg' });
  }

  private async _fetch(): Promise<void> {
    try {
      this.entries.set(changelogEntries(await firstValueFrom(this._http.get<unknown>(CHANGELOG_URL))));
    } catch {
      // No list - a broken upload, a dropped connection: no version, no What's new; the next ask tries again.
      this.entries.set([]);
      this._loading = null;
    }
  }
}

/** The entries of a changelog as the file has them - each version with a version, a date and its English lists; anything else left out. */
export function changelogEntries(value: unknown): ChangelogEntry[] {
  if (!Array.isArray(value)) {
    return [];
  }
  const entries: ChangelogEntry[] = [];
  for (const item of value) {
    if (!isRecord(item) || typeof item['version'] !== 'string' || typeof item['date'] !== 'string') {
      continue;
    }
    const changes = changesOf(item['changes']);
    if (changes === null) {
      continue;
    }
    const translations: Record<string, ChangelogChanges> = {};
    if (isRecord(item['translations'])) {
      for (const [code, lists] of Object.entries(item['translations'])) {
        const translated = changesOf(lists);
        if (translated !== null) {
          translations[code] = translated;
        }
      }
    }
    entries.push({ version: item['version'], date: item['date'], changes, translations });
  }
  return entries;
}

/**
 * A version's lists in `language` - its translation when that is whole (every
 * kind of the English with as many sentences, each with words in it) - or the
 * English; and the language they are in.
 */
export function changesIn(entry: ChangelogEntry, language: string): { changes: ChangelogChanges; lang: string } {
  const base = language.split('-')[0];
  const own = entry.translations ?? {};
  const code = Object.hasOwn(own, language) ? language : Object.hasOwn(own, base) ? base : null;
  const translation = code === null ? undefined : own[code];
  if (base !== 'en' && code !== null && translation && isWhole(entry.changes, translation)) {
    return { changes: translation, lang: code };
  }
  return { changes: entry.changes, lang: 'en' };
}

function isWhole(english: ChangelogChanges, translation: ChangelogChanges): boolean {
  return CHANGE_KINDS.every((kind) => {
    const want = english[kind] ?? [];
    const have = translation[kind] ?? [];
    return want.length === have.length && have.every((sentence) => sentence.trim() !== '');
  });
}

function changesOf(value: unknown): ChangelogChanges | null {
  if (!isRecord(value)) {
    return null;
  }
  const changes: ChangelogChanges = {};
  for (const kind of CHANGE_KINDS) {
    const list = value[kind];
    if (Array.isArray(list)) {
      changes[kind] = list.filter((sentence): sentence is string => typeof sentence === 'string');
    }
  }
  return changes;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}
