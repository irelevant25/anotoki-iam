import { ChangeDetectionStrategy, Component, Injector, afterNextRender, computed, inject, signal, untracked } from '@angular/core';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AdminApi } from '../../../../core/api/admin.api';
import { ApiError, toApiError } from '../../../../core/api/api-error';
import { AdminLanguage, TranslationChanges, TranslationGrid, TranslationKeyRow } from '../../../../core/api/models';
import { LocalizationDrafts } from '../../../../core/i18n/localization-drafts.service';
import { FALLBACK_LANGUAGE, TranslationService } from '../../../../core/i18n/translation.service';
import { AlertComponent } from '../../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../../ui/button/button.component';
import { ConfirmService } from '../../../../ui/confirm/confirm.service';
import { EmptyStateComponent } from '../../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../../ui/page-header/page-header.component';
import { SelectComponent, SelectOption } from '../../../../ui/select/select.component';
import { SpinnerComponent } from '../../../../ui/spinner/spinner.component';
import { ToastService } from '../../../../ui/toast/toast.service';
import { adminErrorText } from '../../admin-errors';
import { saveFile } from '../save-file';
import { TranslationCellComponent } from '../translation-cell/translation-cell.component';
import {
  IMPORT_MAX_BYTES,
  KeyBlock,
  blockMatches,
  blockMissing,
  cellValue,
  editCount,
  keyBlocks,
  remainingEdits,
  storedValue,
  stringsFromFile,
  translationFileText,
  withEdit,
} from '../translation-grid';
import { HoldsUnsavedChanges } from '../unsaved-changes.guard';

/** A save the server refused: its sentence, and the string it is about. */
interface Refusal {
  message: string;
  key: string;
  language: string | null;
}

/** A file that was not imported, and why - said above the grid, beside the buttons that chose it. */
interface Notice {
  heading: string;
  message: string;
}

/** What a mail is, for the head of its block: the keys say `mail.reset`, a person says "the password reset mail". */
interface MailAbout {
  title: string;
  sent: string;
}

const MAILS: Record<string, MailAbout> = {
  'mail.confirm': { title: 'The confirmation mail', sent: 'Sent after registering, and whenever the link is asked for again.' },
  'mail.reset': { title: 'The password reset mail', sent: 'Sent from "Forgotten password".' },
  'mail.loginCode': { title: 'The sign-in code mail', sent: 'Sent from "Email me a sign-in code".' },
  'mail.approved': { title: 'The account approved mail', sent: 'Sent when an administrator approves an account that waited (Approvals), once its address is confirmed.' },
};

/** One of the grid's two parts: its heading, the line that says what its strings may hold, and its blocks. */
interface Part {
  id: string;
  heading: string;
  lead: string;
  blocks: KeyBlock[];
}

/**
 * What a mail's words may hold - the server's rules (translationsCheck() in
 * backend/api/translations.php), said once above the mails. A save that breaks
 * one is refused, and the server's own sentence is shown beside the key.
 */
const MAILS_LEAD =
  'A mail\'s words hold exactly the placeholders its key lists, on one line, and no address - no link, no "www.", no @: the link or the code is the IAM\'s to put in, on a line of its own. ' +
  'A subject is at most 150 characters, any other string at most 400 - fewer in a script whose letters take several bytes each.';

const PAGES_LEAD = 'Another language may leave a placeholder of the English string out, and never adds one; the English string keeps its own. A string is drawn as text: no HTML.';

/** More languages than this no longer fit side by side: each key's strings go under one another instead. */
const COLUMNS_MAX = 3;

/** The server's word for "the tables are not there yet": migration 006 is uploaded and not applied. */
const UNAVAILABLE = 'translations_unavailable';

/**
 * /admin/translations - the IAM's own words, in every language: a key a row,
 * a language a column, each string a box. The pages' keys, and - apart from
 * them, a block a mail - the words of the four mails the IAM sends.
 *
 * What is typed is held until Save, which sends only the boxes that changed;
 * the server checks all of them before it writes any, and answers the grid
 * as it is now - with what other people saved meanwhile. It is held in
 * LocalizationDrafts, not here: a session that ends, or a sign-out, takes the
 * page away with no question, and what was typed must be in its boxes again
 * when the page is back.
 *
 * What became of a save is said where Save is, in the bar that stays in
 * view: a refusal about one key beside that key as well, in the server's
 * words. An emptied box in another language than English means "no string of
 * its own": the key reads in English there.
 *
 * The keys themselves are the code's: migrations write them, and nothing here
 * makes or removes one.
 *
 * Between an upload and "Apply pending" the tables these words live in do not
 * exist yet: the API says so (503), and the page points at Migrations instead
 * of showing an error.
 */
@Component({
  selector: 'app-translations',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    SpinnerComponent,
    TranslationCellComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './translations.component.html',
  styleUrl: './translations.component.scss',
})
export class TranslationsComponent implements HoldsUnsavedChanges {
  private readonly _api = inject(AdminApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _confirm = inject(ConfirmService);
  private readonly _toast = inject(ToastService);
  private readonly _injector = inject(Injector);
  private readonly _drafts = inject(LocalizationDrafts);

  private readonly _grid = signal<TranslationGrid | null>(null);
  /**
   * The boxes that do not say what the IAM has: key -> language -> text.
   * Kept outside the page, so they outlive it; the browser's question before
   * the tab is closed is asked there too.
   */
  private readonly _edits = this._drafts.strings;

  protected readonly loadError = signal<string | null>(null);
  /** The tables are not there yet: a database update is waiting on the Migrations page. */
  protected readonly unavailable = signal(false);
  protected readonly search = signal('');
  /** A language's code: only the keys it has no string of its own for. */
  protected readonly missingIn = signal('');
  protected readonly saving = signal(false);
  /** The language being exported, and the one being imported, just now. */
  protected readonly exporting = signal<string | null>(null);
  protected readonly importing = signal<string | null>(null);
  protected readonly refusal = signal<Refusal | null>(null);
  /**
   * Why the last save saved nothing, when that is about no key of the grid -
   * no answer, the server failing, a language that is gone: said in the save
   * bar, which is in view wherever the page is scrolled to.
   */
  protected readonly saveFailure = signal<string | null>(null);
  protected readonly notice = signal<Notice | null>(null);

  protected readonly fallback = FALLBACK_LANGUAGE;
  protected readonly loaded = computed(() => this._grid() !== null);
  protected readonly languages = computed(() => this._grid()?.languages ?? []);
  protected readonly total = computed(() => this._grid()?.keys.length ?? 0);
  protected readonly stacked = computed(() => this.languages().length > COLUMNS_MAX);
  protected readonly changed = computed(() => editCount(this._edits()));

  private readonly _codes = computed(() => this.languages().map((language) => language.code));
  private readonly _blocks = computed(() => keyBlocks(this._grid()?.keys ?? []));

  /**
   * The blocks the search and the filter leave. A search looks at what stands
   * in the boxes too - but which rows match is worked out when the search, the
   * filter or the grid changes, never while a box is being typed in: a row
   * found only by its unsaved words would leave, with the cursor in it, at the
   * keystroke that changes them. (The grid changes with every save and
   * import, so the rows are looked at again then.)
   */
  protected readonly visible = computed(() => {
    const search = this.search();
    const missingIn = this.missingIn();
    const codes = this._codes();
    const edits = untracked(this._edits);
    return this._blocks().filter((block) => (missingIn === '' || blockMissing(block, missingIn)) && blockMatches(block, search, codes, edits));
  });
  /**
   * What is left, in the grid's two parts: the mails, apart from the pages'
   * keys - they are held to other rules, and read as messages - and then the
   * pages. A part nothing is left of is not drawn.
   */
  protected readonly parts = computed<Part[]>(() => {
    const visible = this.visible();
    return [
      { id: 'mails', heading: 'Mails', lead: MAILS_LEAD, blocks: visible.filter((block) => block.mail) },
      { id: 'pages', heading: 'Pages', lead: PAGES_LEAD, blocks: visible.filter((block) => !block.mail) },
    ].filter((part) => part.blocks.length > 0);
  });
  protected readonly visibleKeys = computed(() => this.visible().reduce((count, block) => count + block.rows.length, 0));

  /** How much of each language is written - by what is saved. */
  protected readonly coverage = computed(() => {
    const keys = this._grid()?.keys ?? [];
    return this.languages().map((language) => {
      const done = keys.filter((key) => storedValue(key, language.code) !== '').length;
      return { language, done, total: keys.length, percent: keys.length ? Math.round((100 * done) / keys.length) : 0 };
    });
  });

  /** "Missing in Slovak" for every language that can miss a string - English cannot. */
  protected readonly missingOptions = computed<SelectOption[]>(() =>
    this.languages()
      .filter((language) => language.code !== FALLBACK_LANGUAGE)
      .map((language) => ({ value: language.code, label: `Missing in ${language.name}` })),
  );

  protected readonly missingName = computed(() => this.languages().find((language) => language.code === this.missingIn())?.name ?? '');

  constructor() {
    // What was typed before the page was taken away is in the drafts already -
    // once they are known to be this person's; the grid that comes says which
    // of it is still a change.
    this._drafts.settle();
    void this.load();
  }

  /**
   * Leaving with boxes not saved asks first - and "Leave" is what gives them
   * up: they are kept outside the page, so they would be in their boxes again
   * the next time, against what was just answered.
   */
  canLeave(): boolean | Promise<boolean> {
    const changed = this.changed();
    if (!changed) {
      return true;
    }
    return this._confirm
      .ask({
        title: 'Leave without saving?',
        message: `${changed === 1 ? '1 changed string is' : `${changed} changed strings are`} not saved yet, and leaving loses ${changed === 1 ? 'it' : 'them'}.`,
        confirmLabel: 'Leave',
        cancelLabel: 'Stay here',
        tone: 'danger',
      })
      .then((leave) => {
        if (leave) {
          this._edits.set({});
        }
        return leave;
      });
  }

  protected async load(): Promise<void> {
    this.loadError.set(null);
    this.unavailable.set(false);
    try {
      this._take(await firstValueFrom(this._api.translationGrid()));
    } catch (error) {
      const failure = toApiError(error);
      if (failure.code === UNAVAILABLE) {
        this.unavailable.set(true);
      } else {
        this.loadError.set(adminErrorText(failure));
      }
    }
  }

  // ── The boxes ──────────────────────────────────────────────────────────────

  protected valueOf(key: TranslationKeyRow, code: string): string {
    return cellValue(this._edits(), key, code);
  }

  protected isChanged(key: TranslationKeyRow, code: string): boolean {
    const cells = this._edits()[key.name];
    return cells !== undefined && Object.hasOwn(cells, code);
  }

  protected isRefused(key: TranslationKeyRow, code: string): boolean {
    const refusal = this.refusal();
    return refusal !== null && refusal.key === key.name && refusal.language === code;
  }

  protected setValue(key: TranslationKeyRow, code: string, value: string): void {
    // While a save or an import is on its way the grid is about to change: what
    // is typed then is held whatever it says, and measured against the answer.
    const onItsWay = this.saving() || this.importing() !== null;
    this._edits.update((edits) => withEdit(edits, key, code, value, onItsWay));
    // The refusal was about what stood here: changing it is the answer to it.
    const refusal = this.refusal();
    if (refusal && refusal.key === key.name && (refusal.language === code || refusal.language === null)) {
      this.refusal.set(null);
    }
  }

  /** "Required" for English - every other language falls back to it - and what an empty box means for the others. */
  protected placeholderFor(language: AdminLanguage): string {
    return language.code === FALLBACK_LANGUAGE ? 'Required' : 'Falls back to English';
  }

  /** What a mail's block is headed with; a mail this page has not heard of is named by its keys alone. */
  protected mailAbout(block: KeyBlock): MailAbout | null {
    return Object.hasOwn(MAILS, block.name) ? MAILS[block.name] : null;
  }

  protected rowId(name: string): string {
    return `key-${name}`;
  }

  /** Every box back to what the IAM has. */
  protected async discard(): Promise<void> {
    const changed = this.changed();
    const sure = await this._confirm.ask({
      title: changed === 1 ? 'Discard the change?' : `Discard ${changed} changes?`,
      message: `${changed === 1 ? 'The box goes' : 'The boxes go'} back to what the IAM has now.`,
      confirmLabel: 'Discard',
      tone: 'danger',
    });
    if (sure) {
      this._edits.set({});
      this.refusal.set(null);
      this.saveFailure.set(null);
    }
  }

  protected showEverything(): void {
    this.search.set('');
    this.missingIn.set('');
  }

  protected onSearch(event: Event): void {
    this.search.set((event.target as HTMLInputElement).value);
  }

  // ── Saving ─────────────────────────────────────────────────────────────────

  protected async save(): Promise<void> {
    const sent = this._edits();
    const count = editCount(sent);
    if (!count || this.saving()) {
      return;
    }
    this.saving.set(true);
    this.notice.set(null);
    this.saveFailure.set(null);
    try {
      const grid = await firstValueFrom(this._api.saveTranslations(sent));
      this.refusal.set(null);
      this._take(grid, sent);
      this._toast.success(count === 1 ? 'The string is saved.' : `${count} strings are saved.`);
      this._refreshSite();
    } catch (error) {
      // A box typed back to what is stored while the save was out was held
      // for the answer; none came, so it is no change after all.
      this._settleEdits();
      await this._refused(toApiError(error));
    } finally {
      this.saving.set(false);
    }
  }

  /**
   * Nothing was saved - and the bar Save is in says so, whatever the reason.
   *
   * When the server names a key of the grid, its sentence goes beside that key
   * as well, and the key is brought into view - out of the search or the
   * filter, if they hide it. Anything else (no answer, the server failing) is
   * about no key, and is said in the bar alone.
   *
   * A refusal because a language or a key is gone - deleted while its boxes
   * were being filled in here - cannot be answered by changing a box: the page
   * reads the grid again, which lets go of what was typed for the thing that
   * is gone and keeps the rest, and says so; the next Save sends the rest.
   */
  private async _refused(error: ApiError): Promise<void> {
    const message = adminErrorText(error);
    const key = typeof error.details['key'] === 'string' ? error.details['key'] : null;
    const language = typeof error.details['language'] === 'string' ? error.details['language'] : null;

    if (error.code === 'unknown_language' || error.code === 'unknown_key') {
      let fresh: TranslationGrid | null = null;
      try {
        fresh = await firstValueFrom(this._api.translationGrid());
      } catch {
        // The grid could not be read again: the refusal is shown as any other,
        // below - after what was typed meanwhile is measured against the grid there is.
        this._settleEdits();
      }
      if (fresh) {
        this._take(fresh);
        const gone = error.code === 'unknown_language' ? language === null || !fresh.languages.some((other) => other.code === language) : key === null || !fresh.keys.some((row) => row.name === key);
        if (gone) {
          this.refusal.set(null);
          this.saveFailure.set(`${message}${/[.!?]$/.test(message) ? '' : '.'} What was typed for it could not be kept.${this.changed() ? ' Save again for the rest.' : ''}`);
          return;
        }
      }
    }

    if (key === null || !this._grid()?.keys.some((row) => row.name === key)) {
      this.refusal.set(null);
      this.saveFailure.set(message);
      return;
    }
    this.refusal.set({ message, key, language });
    if (!this.visible().some((block) => block.rows.some((row) => row.key.name === key))) {
      this.showEverything();
    }
    afterNextRender(
      () => {
        const row = document.getElementById(this.rowId(key));
        row?.scrollIntoView?.({ block: 'center' });
        row?.querySelector<HTMLTextAreaElement>('textarea[aria-invalid="true"]')?.focus({ preventScroll: true });
      },
      { injector: this._injector },
    );
  }

  // ── A language as a file ───────────────────────────────────────────────────

  /** The language's own strings as the IAM has them, as a JSON file - fetched under the session, saved from here. */
  protected async exportLanguage(language: AdminLanguage): Promise<void> {
    this.exporting.set(language.code);
    try {
      const values = await firstValueFrom(this._api.exportTranslations(language.code));
      const count = Object.keys(values).length;
      saveFile(`iam-translations-${language.code}.json`, translationFileText(values), 'application/json');
      const unsaved = Object.values(this._edits()).some((cells) => Object.hasOwn(cells, language.code));
      this._toast.success(
        `${count === 1 ? '1 string' : `${count} strings`} of ${language.name} ${count === 1 ? 'is' : 'are'} downloaded.${unsaved ? ' What you have not saved yet is not in the file.' : ''}`,
      );
    } catch (error) {
      this._toast.error(`${language.name} could not be exported: ${adminErrorText(toApiError(error))}`);
    } finally {
      this.exporting.set(null);
    }
  }

  /** A file of strings into a language: checked by the server like a save, all of it or none. */
  protected async importLanguage(language: AdminLanguage, event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
      return;
    }
    const heading = `"${file.name}" was not imported`;
    const tooLarge = file.size > IMPORT_MAX_BYTES;
    const text = tooLarge ? '' : await file.text().catch(() => '');
    // Emptied once the file is read, so choosing the same file again is a change again.
    input.value = '';
    this.notice.set(null);
    if (tooLarge) {
      this.notice.set({ heading, message: 'The file is larger than 1 MB - more than every string of a language takes.' });
      return;
    }
    const values = stringsFromFile(text);
    if (values === null) {
      this.notice.set({ heading, message: 'It is not a language\'s strings: the file must hold one JSON object of "key": "text", as an export does.' });
      return;
    }
    const count = Object.values(values).filter((value) => value.trim() !== '').length;
    if (count === 0) {
      this.notice.set({ heading, message: 'It holds no string: every value in it is empty.' });
      return;
    }
    const sure = await this._confirm.ask({
      title: `Import ${count === 1 ? '1 string' : `${count} strings`} into ${language.name}?`,
      message: 'Each string in the file replaces the one the IAM has for its key. Keys the file leaves out, or leaves empty, stay as they are.',
      confirmLabel: 'Import',
    });
    if (!sure) {
      return;
    }

    this.importing.set(language.code);
    try {
      this._take(await firstValueFrom(this._api.importTranslations(language.code, values)));
      // A fresh grid: what an earlier save was refused over, or failed on, is no longer what stands here.
      this.refusal.set(null);
      this.saveFailure.set(null);
      this._toast.success(`The strings of ${language.name} are imported.`);
      this._refreshSite();
    } catch (error) {
      this._settleEdits();
      this.notice.set({ heading, message: adminErrorText(toApiError(error)) });
    } finally {
      this.importing.set(null);
    }
  }

  /** A fresh grid: what is typed and still differs from it stays typed. */
  private _take(grid: TranslationGrid, sent: TranslationChanges = {}): void {
    this._grid.set(grid);
    this._edits.update((edits) => remainingEdits(edits, grid, sent));
    if (!grid.languages.some((language) => language.code === this.missingIn())) {
      this.missingIn.set('');
    }
  }

  /** The edits measured against the grid the page has: a box held while a request was out, and saying what is stored, is let go. */
  private _settleEdits(): void {
    const grid = this._grid();
    if (grid) {
      this._edits.update((edits) => remainingEdits(edits, grid));
    }
  }

  /**
   * The sign-in and account pages read these strings: this tab gets them again
   * now, not at the next reload. (The panel itself is English, and reads the
   * English that comes beside them.)
   */
  private _refreshSite(): void {
    void this._i18n.reload();
  }
}
