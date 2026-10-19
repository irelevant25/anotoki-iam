import { inject } from '@angular/core';
import { AnotokiFeedbackConfig } from '@anotoki/lib/feedback';
import { SessionService } from '../auth/session.service';
import { TranslationService } from '../i18n/translation.service';

/**
 * How the IAM plugs into the anotoki family's feedback form
 * (@anotoki/lib/feedback/form, loaded with the first press: the family's
 * footer opens it under every page and in the admin panel - its Feedback,
 * `<anotoki-site-footer feedback>` - and so does the Contact page; the
 * owner's decision of 2026-10-19). `provideAnotokiFeedback()` runs it in an
 * injection context, the first time the form is made.
 *
 * - The language: the page's (English in the admin panel); the form's words
 *   the library's keys (`anotoki.feedback.*`) from the database - an owner's
 *   rewording on the Translations page - else its built-in English and
 *   informal Slovak, which is the IAM's.
 * - The library's kinds (something does not work, an idea, something else),
 *   and no places to choose from.
 * - Who is signed in: the session's account. The server takes the sender
 *   from this browser's session cookie whatever the page knows (the backend's
 *   optionalSession()), so the form asks the session first where nothing has
 *   yet - a sign-in page that never needed it -, that its "send it without
 *   my name" and the address it writes in are true of the message that goes.
 * - The account's address written in as the reply address, until the person
 *   types their own: the IAM holds it already, and the person sees it and may
 *   clear it - the server never adds one.
 *
 * It sends to `POST /api/feedback` (backend routes/login.php) through the
 * app's HttpClient: the IAM's interceptor gives it the CSRF header every
 * write carries, and hands its failure back as it came - the form says a
 * refusal in the reader's words (core/http/iam.interceptor.ts). No mail is
 * sent: the admin panel's Feedback page counts what is new.
 */
export function iamFeedbackConfig(): AnotokiFeedbackConfig {
  const i18n = inject(TranslationService);
  const session = inject(SessionService);
  if (!session.known()) {
    // The account, or nobody - asked once; a failure leaves the form as for nobody, and the server decides as ever.
    session.ensure().subscribe({ error: () => undefined });
  }
  return {
    language: () => i18n.effectiveLanguage(),
    translate: (key) => i18n.libraryWord(key),
    signedIn: () => session.signedIn(),
    email: () => session.user()?.email ?? null,
  };
}
