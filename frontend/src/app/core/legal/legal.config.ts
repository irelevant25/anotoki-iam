import { inject } from "@angular/core";
import { AnotokiLegalConfig } from "@anotoki/lib/legal";
import { TranslationKey } from "../i18n/en";
import { TranslationService } from "../i18n/translation.service";

/**
 * The library's legal words the IAM says in its own (`legal.*`, migration
 * 020), each read from the IAM's key in place of the library's - as the kit's
 * words are (core/kit/kit-words.ts). Terms' first heading is the library's
 * "What {site} is", and the IAM's name standing alone in it reads oddly ("What
 * anotoki account is"): the IAM's is "About the anotoki account". Each key is
 * written out whole, so the backend's scan of the pages finds it used.
 */
const IAM_LEGAL_WORDS: Readonly<Record<string, TranslationKey>> = {
  "anotoki.legal.termsWhatTitle": "legal.termsWhatTitle",
};

/**
 * How the IAM plugs into the anotoki family's legal pages (@anotoki/lib/legal:
 * Terms, Privacy and Contact on the lazy routes /terms, /privacy and
 * /contact, and the footer's links to them - layouts/site-footer).
 * `provideAnotokiLegal()` runs it in an injection context.
 *
 * - The language: the page's (English in the admin panel).
 * - The words: the library's (`anotoki.legal.*`, `anotoki.legalLinks.*`) - the
 *   database's string of a library key, an owner's rewording on the
 *   Translations page, before the library's built-in English and Slovak - but
 *   for the few the IAM says itself (IAM_LEGAL_WORDS), its own key's string.
 * - The paths: the library's own, /terms, /privacy and /contact (LEGAL_PAGES,
 *   core/auth/next-path.ts) - the IAM's Privacy page is the one every anotoki
 *   site links to for the account.
 * - The operator: the family's (Code Hero s.r.o.).
 * - The facts - what the IAM keeps, its cookies, who else handles data - are
 *   read by the pages alone, and their frame gives them (core/legal/legal-facts.ts,
 *   layouts/legal-layout): none of it is in the first load, which the
 *   footer's links share with every sign-in page.
 */
export function iamLegalConfig(): AnotokiLegalConfig {
  const i18n = inject(TranslationService);
  return {
    language: () => i18n.effectiveLanguage(),
    translate: (key) => (Object.hasOwn(IAM_LEGAL_WORDS, key) ? i18n.t(IAM_LEGAL_WORDS[key]) : i18n.libraryWord(key)),
    facts: () => {
      throw new Error("The legal pages' facts come with their frame (layouts/legal-layout, core/legal/legal-facts.ts).");
    },
  };
}
