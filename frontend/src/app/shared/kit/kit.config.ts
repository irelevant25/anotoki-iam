import { inject } from '@angular/core';
import { AnotokiShellConfig } from '@anotoki/lib/shell';
import { AnotokiUiConfig } from '@anotoki/lib/ui/config';
import { firstValueFrom } from 'rxjs';
import { AccountApi } from '../../api/account.api';
import { SessionService } from '../auth/session.service';
import { TranslationService } from '../i18n/translation.service';
import { IAM_ICONS } from './icons';
import { iamKitWord } from './kit-words';

/**
 * Shared with the theme boot script's `data-storage-key` (src/index.html), which
 * applies the same choice before the app has loaded - the IAM's key since
 * before the family's kit, so nobody's stored theme is lost.
 */
export const THEME_STORAGE_KEY = 'anotoki-iam:theme';

/**
 * How the IAM plugs into the anotoki family's UI kit (@anotoki/lib/ui and its
 * entry points): the language its words are in (the page's - English in the
 * admin panel), their words from the database, and the icons the IAM's
 * templates name. `provideAnotokiUi()` runs it in an injection context.
 *
 * A kit word the IAM says with a key of its own reads that key's string
 * (shared/kit/kit-words.ts); any other reads the database's string of the
 * library's key for it (`anotoki.<key>`, the translations' `forKit()`) - so
 * an administrator's rewording on the Translations page is what the kit shows
 * either way - and the kit's own words where the database has none.
 *
 * The dates (`@anotoki/lib/ui/dates`: the `anotokiDate` and `anotokiAgo`
 * pipes, the functions under them) are written in the page's locale - the
 * translations' (en-GB, sk-SK; English in the admin panel) - in the account's
 * date order and on its clock while somebody is signed in and chose them
 * (migration 015, the session's `preferences`), the page language's own way
 * otherwise.
 */
export function iamUiConfig(): AnotokiUiConfig {
  const i18n = inject(TranslationService);
  const session = inject(SessionService);
  const kit = i18n.forKit();
  return {
    language: kit.language,
    lookup: (key) => iamKitWord(i18n, key) ?? kit.lookup(key),
    locale: kit.locale,
    dateChoices: () => session.user()?.preferences ?? null,
    icons: IAM_ICONS,
  };
}

/**
 * How the IAM plugs into the family's shell (@anotoki/lib/shell: the theme,
 * the language switcher, the top bar and the frame).
 *
 * - The theme: signed in, the account's (migration 015, the owner's decision
 *   of 2026-10-06) - one choice every anotoki site follows, shown from the
 *   moment the account is known and saved to it from any switch on these
 *   pages (one save at a time, the latest last; a refusal puts the account's
 *   back, and the switch says so). Signed out - the sign-in pages - it is this
 *   device's. Saved through the IAM's own API with the session cookie, and the
 *   account it answers with is the session's from then on - unless the tab
 *   signed out while it was on its way (SessionService.setFrom()): an answer
 *   after Sign out signs nobody back in.
 * - The languages: the database's, each under its own name ("Slovenčina"),
 *   through the translations' `forShell()`: a choice - the top bar's, the
 *   sign-in pages' - switches the page once its words have come, and while
 *   somebody is signed in it is saved to the account, one save at a time and
 *   the latest last; a refusal is the note under the switcher
 *   (shared/i18n/translations.config.ts).
 */
export function iamShellConfig(): AnotokiShellConfig {
  const session = inject(SessionService);
  const accountApi = inject(AccountApi);
  const i18n = inject(TranslationService);
  return {
    theme: {
      storageKey: THEME_STORAGE_KEY,
      account: () => session.user()?.preferences?.theme ?? null,
      save: (theme) => firstValueFrom(session.setFrom(accountApi.updateProfile({ theme }))),
    },
    languages: i18n.forShell(),
  };
}
