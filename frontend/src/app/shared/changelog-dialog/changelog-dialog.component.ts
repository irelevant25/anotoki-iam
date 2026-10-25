import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { ANOTOKI_DIALOG_DATA, AnotokiDialogRef, DialogFooterDirective } from '@anotoki/lib/ui/dialog';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { type ChangeKind, type ChangelogEntry, changesIn } from '../changelog/changelog.service';
import { TranslationKey } from '../i18n/en';
import { TPipe } from '../i18n/t.pipe';
import { TranslationService } from '../i18n/translation.service';
import { IconName } from '../kit/icons';

/** Each kind of change: the key of its heading, and its icon (the kit's own). */
const KINDS: readonly { kind: ChangeKind; key: TranslationKey; icon: IconName }[] = [
  { kind: 'added', key: 'whatsNew.added', icon: 'plus' },
  { kind: 'fixed', key: 'whatsNew.fixed', icon: 'check' },
  { kind: 'updated', key: 'whatsNew.updated', icon: 'refresh' },
];

/** A version as the dialog draws it: its lists in the reader's language where they are whole, and that language where it is not the page's. */
interface ShownVersion {
  version: string;
  /** Its day, as the file says it (`2026-10-19`): a calendar day, the same day in every time zone (`anotokiDate`). */
  day: string;
  lang: string | null;
  lists: { kind: ChangeKind; key: TranslationKey; icon: IconName; sentences: string[] }[];
}

/**
 * What's new: the IAM's versions it is handed (Changelog.open() - every one,
 * from public/changelog.json), newest first, each with what it added, put
 * right and changed - in the kit's dialog (the anotoki family's AnotokiDialog:
 * its focus, Escape and backdrop, the focus back where it was).
 *
 * The frame's words are the IAM's keys (`whatsNew.*`, migration 024, in the
 * language on the page: English in the admin panel) - the heading, a
 * version's name, Latest, the three headings - with Close, and the date in
 * the account's order (the family's `anotokiDate`). The sentences are the file's: a version's
 * translation into the reader's language when it is whole (`translations.sk`:
 * the IAM's informal Slovak), its English otherwise - and then its lists say
 * they are English.
 */
@Component({
  selector: 'app-changelog-dialog',
  imports: [AnotokiDatePipe, BadgeComponent, ButtonComponent, DialogFooterDirective, IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './changelog-dialog.component.html',
  styleUrl: './changelog-dialog.component.scss',
})
export class ChangelogDialogComponent {
  private readonly _entries = inject(ANOTOKI_DIALOG_DATA) as readonly ChangelogEntry[];
  private readonly _i18n = inject(TranslationService);
  protected readonly ref = inject(AnotokiDialogRef);

  protected readonly versions = computed<ShownVersion[]>(() => {
    const language = this._i18n.effectiveLanguage();
    return this._entries.map((entry) => {
      const { changes, lang } = changesIn(entry, language);
      return {
        version: entry.version,
        day: entry.date,
        lang: lang === language ? null : lang,
        lists: KINDS.map((kind) => ({ ...kind, sentences: changes[kind.kind] ?? [] })).filter((list) => list.sentences.length > 0),
      };
    });
  });

  constructor() {
    // The dialog's heading is set here, so it follows the language on the page.
    effect(() => this.ref.heading.set(this._i18n.t('whatsNew.title')));
  }
}
