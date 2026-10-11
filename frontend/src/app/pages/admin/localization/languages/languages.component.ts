import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent, BadgeComponent, ButtonComponent, CardComponent, ErrorStateComponent, IconComponent, PageHeaderComponent, SpinnerComponent } from '@anotoki/lib/ui';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { SwitchComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { ToastService } from '@anotoki/lib/ui/toast';
import { firstValueFrom } from 'rxjs';
import { AdminApi } from '../../../../core/api/admin.api';
import { toApiError } from '../../../../core/api/api-error';
import { AdminLanguage, LanguageChanges } from '../../../../core/api/models';
import { LanguageDraft, LocalizationDrafts } from '../../../../core/i18n/localization-drafts.service';
import { FALLBACK_LANGUAGE, TranslationService } from '../../../../core/i18n/translation.service';
import { adminErrorText } from '../../admin-errors';
import { HoldsUnsavedChanges } from '../unsaved-changes.guard';

/** A language code as the database keeps it - the same expression as languageCodeOk() in PHP. */
const LANGUAGE_CODE = /^[a-z]{2}(-[a-z]{2})?$/;
/** A place in the order: a whole number from 0 to 1000, as the server takes it. */
const SORT_ORDER = /^\d{1,4}$/;
const SORT_ORDER_MAX = 1000;

/** The server's word for "the tables are not there yet": migration 006 is uploaded and not applied. */
const UNAVAILABLE = 'translations_unavailable';

/** The order a chooser shows them in - the server's: by their place, then by name, then by code. */
function inOrder(languages: AdminLanguage[]): AdminLanguage[] {
  return [...languages].sort((a, b) => a.sort_order - b.sort_order || a.name.localeCompare(b.name, 'en') || a.code.localeCompare(b.code, 'en'));
}

/** A row's fields as they are saved: what a draft is measured against. */
function savedDraft(language: AdminLanguage): LanguageDraft {
  return { name: language.name, native_name: language.native_name, sort_order: String(language.sort_order) };
}

function sameDraft(a: LanguageDraft, b: LanguageDraft): boolean {
  return a.name === b.name && a.native_name === b.native_name && a.sort_order === b.sort_order;
}

/** "1 account has" / "3 accounts have" - for the sentences that say whose language it is. */
function accountsHave(count: number): string {
  return count === 1 ? '1 account has' : `${count} accounts have`;
}

/**
 * /admin/languages - the languages the IAM's pages and mails can be read in:
 * their names, their order on the switcher, whether people are offered them,
 * and how many accounts have each.
 *
 * English is what every other language falls back to: it cannot be hidden or
 * deleted. A language the IAM is released in (the migrations write strings
 * for it) can be hidden but not deleted; any other can be deleted, and the
 * accounts that had it go back to English. The page only hides what the
 * server refuses anyway, and shows the server's sentence when it does refuse.
 *
 * A new language starts hidden unless the person adding it says otherwise:
 * with no string of its own yet it would read in English throughout, under
 * its own name on the switcher - and in discovery, where every site sees it.
 *
 * What is typed in a row and not saved is held in LocalizationDrafts, not
 * here: a session that ends, or a sign-out, takes the page away with no
 * question, and the rows must be as they were typed when the page is back.
 *
 * Between an upload and "Apply pending" the `languages` table does not exist
 * yet: the API says so (503), and the page points at Migrations instead of
 * showing an error.
 */
@Component({
  selector: 'app-languages',
  imports: [RouterLink, AlertComponent, BadgeComponent, ButtonComponent, CardComponent, ErrorStateComponent, IconComponent, PageHeaderComponent, SpinnerComponent, SwitchComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './languages.component.html',
  styleUrl: './languages.component.scss',
})
export class LanguagesComponent implements HoldsUnsavedChanges {
  private readonly _api = inject(AdminApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _confirm = inject(ConfirmService);
  private readonly _toast = inject(ToastService);
  private readonly _store = inject(LocalizationDrafts);

  private readonly _languages = signal<AdminLanguage[] | null>(null);
  /**
   * The rows whose fields do not say what is saved, by code. Kept outside the
   * page, so they outlive it; the browser's question before the tab is closed
   * is asked there too.
   */
  private readonly _drafts = this._store.languages;
  /** What the server said when it refused a row's change, by code - its own sentence. */
  private readonly _errors = signal<Record<string, string>>({});

  protected readonly loadError = signal<string | null>(null);
  /** The table is not there yet: a database update is waiting on the Migrations page. */
  protected readonly unavailable = signal(false);
  /** The language whose names and order are being saved, whose switch is being saved, and the one being deleted. */
  protected readonly saving = signal<string | null>(null);
  protected readonly switching = signal<string | null>(null);
  protected readonly deleting = signal<string | null>(null);

  protected readonly newCode = signal('');
  protected readonly newName = signal('');
  protected readonly newNativeName = signal('');
  /** Offered to readers the moment it is added - off unless the person says so: a new language has no strings yet. */
  protected readonly newOffered = signal(false);
  protected readonly adding = signal(false);
  protected readonly addError = signal<string | null>(null);

  protected readonly fallback = FALLBACK_LANGUAGE;
  protected readonly loaded = computed(() => this._languages() !== null);
  protected readonly languages = computed(() => this._languages() ?? []);
  protected readonly offered = computed(() => this.languages().filter((language) => language.enabled).length);
  protected readonly unsaved = computed(() => Object.keys(this._drafts()).length);
  protected readonly canAdd = computed(() => this.newCode().trim() !== '' && this.newName().trim() !== '' && this.newNativeName().trim() !== '');
  /** Said under the code as it is typed - the server's rule, in the server's words. */
  protected readonly newCodeError = computed(() => {
    const code = this.newCode().trim().toLowerCase();
    return code === '' || LANGUAGE_CODE.test(code) ? null : 'A language\'s code is two small letters, or two and two with a dash between: "sk", "pt-br".';
  });

  constructor() {
    // What was typed before the page was taken away is in the drafts already -
    // once they are known to be this person's; the languages that come say
    // which of it is still a change.
    this._store.settle();
    void this.load();
  }

  /**
   * Leaving with a row changed and not saved asks first - and "Leave" is what
   * gives the changes up: they are kept outside the page, so they would be in
   * their rows again the next time, against what was just answered.
   */
  canLeave(): boolean | Promise<boolean> {
    const unsaved = this.unsaved();
    if (!unsaved) {
      return true;
    }
    return this._confirm
      .ask({
        title: 'Leave without saving?',
        message: `${unsaved === 1 ? '1 language has' : `${unsaved} languages have`} changes that are not saved yet, and leaving loses them.`,
        confirmLabel: 'Leave',
        cancelLabel: 'Stay here',
        tone: 'danger',
      })
      .then((leave) => {
        if (leave) {
          this._drafts.set({});
        }
        return leave;
      });
  }

  protected async load(): Promise<void> {
    this.loadError.set(null);
    this.unavailable.set(false);
    try {
      const languages = inOrder(await firstValueFrom(this._api.languages()));
      this._languages.set(languages);
      // Drafts kept from before: not for a language that is gone, and not one that says what is saved now.
      this._drafts.update((drafts) =>
        Object.fromEntries(
          Object.entries(drafts).filter(([code, draft]) => {
            const language = languages.find((other) => other.code === code);
            return language !== undefined && !sameDraft(draft, savedDraft(language));
          }),
        ),
      );
    } catch (error) {
      const failure = toApiError(error);
      if (failure.code === UNAVAILABLE) {
        this.unavailable.set(true);
      } else {
        this.loadError.set(adminErrorText(failure));
      }
    }
  }

  // ── A row ──────────────────────────────────────────────────────────────────

  /** What a row's fields show: what is typed, or what is saved. */
  protected draft(language: AdminLanguage): LanguageDraft {
    const drafts = this._drafts();
    return Object.hasOwn(drafts, language.code) ? drafts[language.code] : savedDraft(language);
  }

  protected isChanged(language: AdminLanguage): boolean {
    return Object.hasOwn(this._drafts(), language.code);
  }

  protected errorOf(language: AdminLanguage): string | null {
    const errors = this._errors();
    return Object.hasOwn(errors, language.code) ? errors[language.code] : null;
  }

  /** "No account has it" / "1 account has it" / "12 accounts have it". */
  protected accountsText(language: AdminLanguage): string {
    return language.accounts === 0 ? 'No account has it' : `${accountsHave(language.accounts)} it`;
  }

  /** Under a row's switch: what offered and hidden mean for this language. */
  protected offeredHint(language: AdminLanguage): string {
    if (language.code === FALLBACK_LANGUAGE) {
      return 'Always offered: every other language falls back to it.';
    }
    return language.enabled
      ? 'People can choose it, and sites are told the IAM speaks it.'
      : 'Nobody is offered it. Its strings stay, and an account that has it reads another language until it is offered again.';
  }

  protected edit(language: AdminLanguage, change: Partial<LanguageDraft>): void {
    this._keep(language, { ...this.draft(language), ...change });
  }

  protected undo(language: AdminLanguage): void {
    this._forget(language.code);
    this._setError(language.code, null);
  }

  /**
   * The names and the place in the order - what changed of them, in one
   * request. The row's fields stay open meanwhile: what is typed in them
   * before the answer comes is a change to what the answer saved, not lost to it.
   */
  protected async save(language: AdminLanguage): Promise<void> {
    if (this.saving() !== null) {
      return;
    }
    const draft = this.draft(language);
    const changes: LanguageChanges = {};
    if (draft.name !== language.name) {
      changes.name = draft.name;
    }
    if (draft.native_name !== language.native_name) {
      changes.native_name = draft.native_name;
    }
    if (draft.sort_order !== String(language.sort_order)) {
      const sortOrder = SORT_ORDER.test(draft.sort_order.trim()) ? Number(draft.sort_order.trim()) : -1;
      if (sortOrder < 0 || sortOrder > SORT_ORDER_MAX) {
        this._setError(language.code, 'The order is a whole number from 0 to 1000.');
        return;
      }
      changes.sort_order = sortOrder;
    }

    this.saving.set(language.code);
    this._setError(language.code, null);
    try {
      const saved = await firstValueFrom(this._api.updateLanguage(language.code, changes));
      // The fields as they stand now: as they were sent - or typed in since the
      // request left (typed back to what was saved before, even: `language` is
      // still that row, so its fields read as they show).
      const now = this.draft(language);
      this._replace(saved);
      if (sameDraft(now, draft)) {
        this._forget(language.code);
      } else {
        // A change again - measured against what is saved now.
        this._keep(saved, now);
      }
      this._toast.success(`${saved.name} is saved.`);
      this._refreshSite();
    } catch (error) {
      this._setError(language.code, adminErrorText(toApiError(error)));
    } finally {
      this.saving.set(null);
    }
  }

  /**
   * Offered or hidden, saved the moment the switch is flipped. The row says
   * the new state at once and goes back to the old one if the server refuses -
   * the switch follows, because its state is the row's.
   */
  protected async setOffered(language: AdminLanguage, enabled: boolean): Promise<void> {
    if (enabled === language.enabled) {
      return;
    }
    this.switching.set(language.code);
    this._setError(language.code, null);
    this._patch(language.code, { enabled });
    try {
      const saved = await firstValueFrom(this._api.updateLanguage(language.code, { enabled }));
      this._patch(language.code, { enabled: saved.enabled, accounts: saved.accounts });
      this._toast.success(saved.enabled ? `${saved.name} is offered to everybody now.` : `${saved.name} is hidden: nobody is offered it.`);
      this._refreshSite();
    } catch (error) {
      this._patch(language.code, { enabled: !enabled });
      this._setError(language.code, adminErrorText(toApiError(error)));
    } finally {
      this.switching.set(null);
    }
  }

  /**
   * Never for a language the IAM is released in - the server refuses those,
   * and the page does not offer it. The question says what goes with the
   * language: its strings, and the choice of every account that has it. How
   * many accounts that was in the end is the server's to say, and the
   * confirmation afterwards says it.
   */
  protected async remove(language: AdminLanguage): Promise<void> {
    const sure = await this._confirm.ask({
      title: `Delete ${language.name} (${language.code})?`,
      message:
        'Every string of the IAM written in it goes with it, and none of it can be brought back. ' +
        (language.accounts === 0
          ? 'No account has it as its language.'
          : `${accountsHave(language.accounts)} it as ${language.accounts === 1 ? 'its' : 'their'} language, and ${language.accounts === 1 ? 'goes' : 'go'} back to English.`),
      confirmLabel: 'Delete',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }
    this.deleting.set(language.code);
    this._setError(language.code, null);
    try {
      const { accounts } = await firstValueFrom(this._api.deleteLanguage(language.code));
      // English has those accounts now, and its row says so.
      this._languages.update((languages) =>
        (languages ?? []).filter((other) => other.code !== language.code).map((other) => (other.code === FALLBACK_LANGUAGE ? { ...other, accounts: other.accounts + accounts } : other)),
      );
      this._forget(language.code);
      this._toast.success(`${language.name} is deleted.${accounts > 0 ? ` ${accounts === 1 ? '1 account went' : `${accounts} accounts went`} back to English.` : ''}`);
      this._refreshSite();
    } catch (error) {
      this._setError(language.code, adminErrorText(toApiError(error)));
    } finally {
      this.deleting.set(null);
    }
  }

  // ── A new language ─────────────────────────────────────────────────────────

  protected async add(): Promise<void> {
    if (!this.canAdd() || this.adding()) {
      return;
    }
    this.adding.set(true);
    this.addError.set(null);
    try {
      // Said every time, on or off: whether readers are offered it is this form's to decide, not a default's.
      const added = await firstValueFrom(
        this._api.createLanguage({ code: this.newCode().trim().toLowerCase(), name: this.newName().trim(), native_name: this.newNativeName().trim(), enabled: this.newOffered() }),
      );
      this._languages.update((languages) => inOrder([...(languages ?? []), added]));
      this.newCode.set('');
      this.newName.set('');
      this.newNativeName.set('');
      this.newOffered.set(false);
      // By what the server made of it - the row says the same.
      this._toast.success(
        added.enabled ? `${added.name} is added, and offered to everybody. It reads in English until its strings are written.` : `${added.name} is added, hidden. Write its strings, then offer it.`,
      );
      this._refreshSite();
    } catch (error) {
      this.addError.set(adminErrorText(toApiError(error)));
    } finally {
      this.adding.set(false);
    }
  }

  private _replace(saved: AdminLanguage): void {
    this._languages.update((languages) => inOrder((languages ?? []).map((language) => (language.code === saved.code ? saved : language))));
  }

  private _patch(code: string, change: Partial<AdminLanguage>): void {
    this._languages.update((languages) => (languages ?? []).map((language) => (language.code === code ? { ...language, ...change } : language)));
  }

  private _forget(code: string): void {
    this._drafts.update(({ [code]: _, ...others }) => others);
  }

  /** A row's fields measured against the row as it is saved: a draft while they differ, none when they say the same. */
  private _keep(language: AdminLanguage, draft: LanguageDraft): void {
    const same = sameDraft(draft, savedDraft(language));
    this._drafts.update(({ [language.code]: _, ...others }) => (same ? others : { ...others, [language.code]: draft }));
  }

  private _setError(code: string, message: string | null): void {
    this._errors.update(({ [code]: _, ...others }) => (message === null ? others : { ...others, [code]: message }));
  }

  /**
   * What else holds the languages reads them again now, not at the next
   * reload: the switcher, the account page's select and the user dialogs take
   * them from the list that comes with the IAM's strings.
   */
  private _refreshSite(): void {
    void this._i18n.reload();
  }
}
