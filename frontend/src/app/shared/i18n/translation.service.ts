import { AbstractType } from '@angular/core';
import { TranslationService as AnotokiTranslationService, TranslationParams } from '@anotoki/lib/translations';
import { LibraryKey } from '@anotoki/lib/ui/config';
import { TranslationKey } from './en';

export { FALLBACK_LANGUAGE } from '@anotoki/lib/translations';
export type { TranslationParams } from '@anotoki/lib/translations';

/** A key the IAM's pages may ask for: its own (en.ts), or one of the anotoki library's words (`anotoki.*`). */
export type WordKey = TranslationKey | LibraryKey;

/** What the IAM's code asks the service for, with its keys typed. */
interface TypedWords {
  t(key: WordKey, params?: TranslationParams): string;
}

/**
 * The IAM's words: the anotoki family's TranslationService
 * (@anotoki/lib/translations), seen with the IAM's keys. The service is the
 * library's - the language the pages are read in and its bundle from
 * `GET /api/translations/{code}` with English under it, the family's ranking
 * of the wishes (this tab's `?lang=`, a choice made on the page, the account's
 * language, this device's, the browser's, English), a choice saved to the
 * account one save at a time and the latest last, the cache and the start's
 * waits, the English admin panel, `preferred()` for a new account, `settled()`
 * for the guard, `reload()` after the admin panel changed the strings - and
 * this is the same object under the same injection token, typed so that a key
 * that does not exist is a compile error in every class, as the typed `t` pipe
 * makes it one in every template:
 *
 *   private readonly _i18n = inject(TranslationService);   // the library's service, the IAM's keys
 *
 * How the library is set up for the IAM is translations.config.ts.
 */
export type TranslationService = Omit<AnotokiTranslationService, keyof TypedWords> & TypedWords;
export const TranslationService = AnotokiTranslationService as unknown as AbstractType<TranslationService>;
