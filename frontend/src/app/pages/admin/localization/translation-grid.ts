import { TranslationChanges, TranslationGrid, TranslationKeyRow } from '../../../core/api/models';

/**
 * What the Translations page works out from the grid the API sends
 * (GET /api/admin/translations) - pure, so it can be read and tried without a
 * page: which keys are one mail, what a search finds, which cells changed.
 */

/** The largest file an import takes - the server's TRANSLATIONS_BODY_LIMIT. */
export const IMPORT_MAX_BYTES = 1024 * 1024;

/** What the keys of the mails' words start with. No page reads them: they are the server's (backend/api/mail.php). */
const MAIL_PREFIX = 'mail.';

/** The mails the IAM sends, then the announcements' templates and the lines every announcement ends with - in the order the page shows them. */
const MAILS = ['confirm', 'reset', 'loginCode', 'approved', 'newVersion', 'newRelease', 'newSurvey', 'news'];

/** A mail's strings in the order the message has them: the subject, then the body from its first line to its last. */
const MAIL_PARTS = ['subject', 'greeting', 'heading', 'intro', 'body', 'button', 'validity', 'ignore', 'footer', 'stop'];

/** One key of a block, and which part of its mail it is (null: a page's key, on its own). */
export interface BlockRow {
  key: TranslationKeyRow;
  part: string | null;
}

/**
 * What the page shows as one piece: a page's key, or a mail - the keys
 * `mail.reset.subject`, `mail.reset.greeting`, ... which the server puts
 * together into one message.
 */
export interface KeyBlock {
  /** The key's name; for a mail, the name without the part: `mail.reset`. */
  name: string;
  mail: boolean;
  rows: BlockRow[];
}

/** Where a name stands in a list that says the order; a name the list does not know comes after all it does. */
function placeIn(order: readonly string[], name: string): number {
  const place = order.indexOf(name);
  return place < 0 ? order.length : place;
}

/**
 * The keys as blocks. The mails come first, apart from the pages' keys: each
 * mail one block (confirm, reset, loginCode, approved, then the announcements'
 * templates and their ending - one the list does not know after them), its
 * strings in the order the message has them (a part the list does not know
 * after those, where it came). Then every other key on its own, in the order
 * they came (by name).
 */
export function keyBlocks(keys: readonly TranslationKeyRow[]): KeyBlock[] {
  const pages: KeyBlock[] = [];
  const mails = new Map<string, KeyBlock>();
  for (const key of keys) {
    if (!key.name.startsWith(MAIL_PREFIX)) {
      pages.push({ name: key.name, mail: false, rows: [{ key, part: null }] });
      continue;
    }
    // `mail.reset.subject` is the subject of `mail.reset`; a key with no part of its own is its own mail.
    const dot = key.name.lastIndexOf('.');
    const name = dot >= MAIL_PREFIX.length ? key.name.slice(0, dot) : key.name;
    const part = dot >= MAIL_PREFIX.length ? key.name.slice(dot + 1) : '';
    let mail = mails.get(name);
    if (!mail) {
      mail = { name, mail: true, rows: [] };
      mails.set(name, mail);
    }
    mail.rows.push({ key, part });
  }
  for (const mail of mails.values()) {
    // sort() is stable: parts the list does not know keep the order they came in.
    mail.rows.sort((a, b) => placeIn(MAIL_PARTS, a.part ?? '') - placeIn(MAIL_PARTS, b.part ?? ''));
  }
  const which = (block: KeyBlock): string => block.name.slice(MAIL_PREFIX.length);
  return [...[...mails.values()].sort((a, b) => placeIn(MAILS, which(a)) - placeIn(MAILS, which(b))), ...pages];
}

/** Text as a search compares it: small letters, and no diacritics - "prihlasenie" finds "Prihlásenie". */
export function searchable(text: string): string {
  return text
    .normalize('NFD')
    .replace(/\p{M}+/gu, '')
    .toLowerCase();
}

/** What is in a cell now: the change not saved yet, or the stored string ('' for none). */
export function cellValue(edits: TranslationChanges, key: TranslationKeyRow, code: string): string {
  const mine = edits[key.name];
  return mine && Object.hasOwn(mine, code) ? mine[code] : storedValue(key, code);
}

/** The string the IAM has for a key in a language ('' for none). */
export function storedValue(key: TranslationKeyRow, code: string): string {
  return Object.hasOwn(key.values, code) ? key.values[code] : '';
}

/**
 * Does a search find this block? By a key's name, its description, or its
 * text in any language - somebody looking for a string knows its words, not
 * its key. The text is both what the IAM has and what stands in the box now:
 * a row found by its words does not leave while they are being rewritten.
 * A mail is found whole.
 */
export function blockMatches(block: KeyBlock, search: string, codes: readonly string[], edits: TranslationChanges): boolean {
  const needle = searchable(search.trim());
  if (needle === '') {
    return true;
  }
  return block.rows.some(
    ({ key }) =>
      searchable(key.name).includes(needle) ||
      searchable(key.description ?? '').includes(needle) ||
      codes.some((code) => searchable(storedValue(key, code)).includes(needle) || searchable(cellValue(edits, key, code)).includes(needle)),
  );
}

/**
 * Has the IAM no string of its own for some key of this block in the
 * language? By what is saved: a row does not leave while it is being filled in.
 */
export function blockMissing(block: KeyBlock, code: string): boolean {
  return block.rows.some((row) => storedValue(row.key, code) === '');
}

/**
 * The edits with one cell set: a cell typed back to what is stored is no
 * change any more - unless `keep` says to hold it whatever it says. That is
 * for typing while a save or an import is on its way: what is stored is about
 * to change, so "the same as stored" cannot be told yet, and what was typed
 * must not be lost to the answer. remainingEdits() lets such a cell go once
 * the fresh grid says the same.
 */
export function withEdit(edits: TranslationChanges, key: TranslationKeyRow, code: string, value: string, keep = false): TranslationChanges {
  const { [key.name]: before, ...others } = edits;
  const cells = { ...before };
  if (!keep && value === storedValue(key, code)) {
    delete cells[code];
  } else {
    cells[code] = value;
  }
  return Object.keys(cells).length ? { ...others, [key.name]: cells } : others;
}

/** How many cells are changed and not saved. */
export function editCount(edits: TranslationChanges): number {
  return Object.values(edits).reduce((count, cells) => count + Object.keys(cells).length, 0);
}

/**
 * The edits that are still changes once a fresh grid has come: not the cells
 * that were `sent` and saved as they were sent (whatever the server made of
 * them - it trims), not a cell that now says what is stored, and nothing for
 * a key or a language that is gone. What was typed while the save was on its
 * way stays.
 */
export function remainingEdits(edits: TranslationChanges, grid: TranslationGrid, sent: TranslationChanges = {}): TranslationChanges {
  const keys = new Map(grid.keys.map((key) => [key.name, key]));
  const codes = new Set(grid.languages.map((language) => language.code));
  const remaining: TranslationChanges = {};
  for (const [name, cells] of Object.entries(edits)) {
    const key = keys.get(name);
    if (!key) {
      continue;
    }
    const kept: Record<string, string> = {};
    for (const [code, value] of Object.entries(cells)) {
      const saved = Object.hasOwn(sent, name) && Object.hasOwn(sent[name], code) && sent[name][code] === value;
      if (codes.has(code) && !saved && value !== storedValue(key, code)) {
        kept[code] = value;
      }
    }
    if (Object.keys(kept).length) {
      remaining[name] = kept;
    }
  }
  return remaining;
}

// ── A language as a file ────────────────────────────────────────────────────

/**
 * A language's strings as the text of a file - one flat object, a key a line,
 * the way `php translations.php --export` writes it, so the two can be
 * compared line by line.
 */
export function translationFileText(values: Record<string, string>): string {
  return JSON.stringify(values, null, 4) + '\n';
}

/** What a file saved by Notepad starts with - a byte-order mark - written by its number: the character itself cannot be seen in a source file. */
const BYTE_ORDER_MARK = String.fromCharCode(0xfeff);

/** The strings a file holds - a flat {key: text} object - or null when it is anything else. */
export function stringsFromFile(text: string): Record<string, string> | null {
  let parsed: unknown;
  try {
    // JSON does not know the mark: it is taken off before the text is read.
    parsed = JSON.parse(text.startsWith(BYTE_ORDER_MARK) ? text.slice(1) : text);
  } catch {
    return null;
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    return null;
  }
  const entries = Object.entries(parsed);
  // fromEntries, so a key like "__proto__" stays a key - and is refused by the server like any other it does not know.
  return entries.every((entry): entry is [string, string] => typeof entry[1] === 'string') ? Object.fromEntries(entries) : null;
}
