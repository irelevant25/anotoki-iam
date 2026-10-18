import { inject } from '@angular/core';
import { AnotokiTranslationsConfig, TranslationsAdminConfig } from '@anotoki/lib/translations';
import { firstValueFrom } from 'rxjs';
import { AccountApi } from '../api/account.api';
import { isLegalPage, isSessionArea } from '../auth/next-path';
import { SessionService } from '../auth/session.service';
import { en } from './en';
import { TranslationService } from './translation.service';

/**
 * Where the IAM kept the language, its words and the admin panel's drafts
 * before the library - the names stay, so a visitor's chosen language and
 * cached words, and an administrator's unsaved strings, survive the upgrade.
 */
export const LANGUAGE_STORAGE = {
  /** This device's choice (localStorage), and this tab's `?lang=` (sessionStorage): one name, two lifetimes. */
  language: 'anotoki-iam:lang',
  /** The last bundle (localStorage): a returning reader starts in their own language while the fresh one is on its way. */
  cache: 'anotoki-iam:lang-cache',
  /** What is typed on the Translations and Languages pages and not saved yet - this tab's (sessionStorage). */
  drafts: 'anotoki-iam:localization-drafts',
} as const;

/**
 * How the IAM plugs into the anotoki family's translations
 * (@anotoki/lib/translations, whose server half is the backend's anotoki/lib):
 * the words of the sign-in and account pages from the database, one bundle per
 * language (`GET /api/translations/{code}`). `provideAnotokiTranslations()`
 * runs it in an injection context, the first time the module needs it.
 *
 * - The storage names the IAM had (LANGUAGE_STORAGE).
 * - The English compiled into the build (en.ts): read only while no bundle is
 *   in memory - between an upload and "Apply pending", or while the server
 *   cannot be reached - so the sign-in page stays readable and an
 *   administrator can sign in to press Apply.
 * - The account: the session's. While somebody is signed in, the account's
 *   language decides (before this device's; only this tab's `?lang=` and a
 *   choice made in this visit come first), and a language chosen on the page -
 *   the bar's switcher, the sign-in page's, the account page's select - is
 *   saved to it with the IAM's own API, one save at a time and the latest
 *   last. The answer is the session's account from then on - unless the tab
 *   signed out while it was on its way (SessionService.setFrom()): an answer
 *   after Sign out signs nobody back in. Every refusal is news here (the IAM
 *   offers exactly the languages its switchers show): the switcher says the
 *   language holds for this visit only. Whose the admin panel's drafts are:
 *   the account's id.
 * - The bundle's request goes through the IAM's HttpClient, marked with the
 *   library's ANOTOKI_TRANSLATION_BUNDLE, which the IAM's interceptor leaves
 *   alone: a failure there means "read the compiled English", never a page to
 *   send anybody to (core/http/iam.interceptor.ts).
 * - The admin panel's Translations and Languages pages, as the IAM words them
 *   (pages/admin/localization.ts) - handed over by the admin panel's routes
 *   when they load (useAdminPages()), so none of it is in the first load.
 *
 * The English areas are the library's default - /admin and under it, the
 * admin panel - and the locales its British English and Slovak, as the IAM's.
 */
export function iamTranslationsConfig(): AnotokiTranslationsConfig {
  const session = inject(SessionService);
  const accountApi = inject(AccountApi);
  return {
    storageKeys: LANGUAGE_STORAGE,
    fallbackEnglish: en,
    account: {
      language: () => session.user()?.language ?? null,
      decides: () => session.signedIn(),
      save: (code) => firstValueFrom(session.setFrom(accountApi.updateProfile({ language: code }))),
      quiet: () => false,
      userKey: () => {
        const user = session.user();
        return user ? String(user.id) : null;
      },
    },
    // Read when the admin pages are made - after the admin panel's routes have handed it over.
    get admin(): TranslationsAdminConfig {
      return adminPages;
    },
  };
}

/** The admin pages' part of the configuration: nothing until the admin panel's routes load. */
let adminPages: TranslationsAdminConfig = {};

/**
 * The admin panel's Translations and Languages pages, as the IAM words them -
 * from the admin panel's routes (their environment initializer), which load
 * before either page can be made: what only those pages read stays in the
 * admin panel's chunk, out of every sign-in page's first load.
 */
export function useAdminPages(config: TranslationsAdminConfig): void {
  adminPages = config;
}

/**
 * The words' start, from the app initializer - `path` is the address the site
 * was opened at. In the account and admin areas the account is asked for
 * first (the guard reuses the answer), so its language - which decides there -
 * is known before the one request for the words; then the library's start:
 * the cached bundle at once when it is in the language wanted, and a wait for
 * the fresh one - a second with a cache, three without. Never rejects.
 *
 * The legal pages are asked for the account too: anybody may read them, and
 * their frame's bar shows whoever is signed in - in the account's language -
 * or Sign in.
 */
export async function startTranslations(session: SessionService, i18n: TranslationService, path: string): Promise<void> {
  // `/` is the account area too: it redirects there.
  if (path === '/' || isSessionArea(path) || isLegalPage(path)) {
    try {
      await firstValueFrom(session.ensure());
    } catch {
      // The server is down or not set up: the guard asks again, and says so.
    }
  }
  await i18n.init();
}
