import { inject } from "@angular/core";
import { AnotokiShellConfig } from "@anotoki/lib/shell";
import { AnotokiUiConfig } from "@anotoki/lib/ui";
import { firstValueFrom } from "rxjs";
import { AccountApi } from "../api/account.api";
import { SessionService } from "../auth/session.service";
import { TranslationService } from "../i18n/translation.service";
import { IAM_ICONS } from "./icons";
import { iamKitWord } from "./kit-words";

/**
 * Shared with the theme boot script's `data-storage-key` (src/index.html), which
 * applies the same choice before the app has loaded - the IAM's key since
 * before the family's kit, so nobody's stored theme is lost.
 */
export const THEME_STORAGE_KEY = "anotoki-iam:theme";

/**
 * How the IAM plugs into the anotoki family's UI kit (@anotoki/lib/ui and its
 * entry points): the language its words are in (English in the admin panel:
 * `effectiveLanguage`), the IAM's own strings for them from the database
 * (core/kit/kit-words.ts), and the icons the IAM's templates name.
 * `provideAnotokiUi()` runs it in an injection context.
 */
export function iamUiConfig(): AnotokiUiConfig {
  const i18n = inject(TranslationService);
  return {
    language: () => i18n.effectiveLanguage(),
    lookup: (key) => iamKitWord(i18n, key),
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
 * - The languages: the database's, each under its own name ("Slovenčina"); a
 *   choice switches the page once its words have come (TranslationService).
 *   Saving a choice made in the top bar to the account is the bar's
 *   (layouts/top-bar), as the account page's select saves its own.
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
    languages: {
      current: () => i18n.language(),
      offered: () => i18n.languages().map((language) => ({ code: language.code, name: language.native_name })),
      choose: (code) => i18n.choose(code),
    },
  };
}
