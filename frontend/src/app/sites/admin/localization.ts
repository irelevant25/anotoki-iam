import { AdminLanguage, LanguageDeleted, TranslationGroup, TranslationsAdminConfig } from '@anotoki/lib/translations';
import { SessionService } from '../../shared/auth/session.service';
import { MIGRATIONS_ROUTE } from '../../shared/migrations/migrations.config';
import { adminErrorText } from './shared/admin-errors';

/**
 * The mails' words: the keys `mail.<mail>.<part>`, which the server alone
 * reads (backend/api/mail.php) and puts together into one message. On the
 * Translations page each mail is one block, apart from the pages' keys: the
 * IAM's own mails, then the announcements' templates and the lines every
 * announcement ends with (a mail not named here comes after them), each
 * mail's strings in the order the message has them (a part not named here
 * after those). The lead says what a mail's string may hold - the server's
 * rules (the library's checks, configured by the IAM in
 * backend/api/translations.php); a save that breaks one is refused, and the
 * server's own sentence is shown beside the key.
 */
const MAILS: TranslationGroup = {
  prefix: 'mail.',
  heading: 'Mails',
  lead:
    'A mail\'s words hold exactly the placeholders its key lists, on one line, and no address - no link, no "www.", no @: the link or the code is the IAM\'s to put in, on a line of its own. ' +
    'A subject is at most 150 characters, any other string at most 400 - fewer in a script whose letters take several bytes each.',
  order: ['confirm', 'reset', 'loginCode', 'approved', 'newSignIn', 'deletionRequested', 'deletionCancelled', 'deleted', 'newVersion', 'newRelease', 'newSurvey', 'news'],
  parts: ['subject', 'greeting', 'heading', 'intro', 'body', 'button', 'validity', 'ignore', 'closing', 'footer', 'stop'],
  about: {
    'mail.confirm': { title: 'The confirmation mail', text: 'Sent after registering, and whenever the link is asked for again.' },
    'mail.reset': { title: 'The password reset mail', text: 'Sent from "Forgotten password".' },
    'mail.loginCode': { title: 'The sign-in code mail', text: 'Sent from "Email me a sign-in code".' },
    'mail.approved': { title: 'The account approved mail', text: 'Sent when an administrator approves an account that waited (Approvals), once its address is confirmed.' },
    'mail.newSignIn': {
      title: 'The new sign-in mail',
      text: "Sent when the account signs in at the IAM from a browser it has not been used on before - never for the account's first browser, at most five an hour.",
    },
    'mail.deletionRequested': { title: 'The deletion asked for mail', text: 'Sent when the person asks for their account to be deleted: the day it will be, and how to cancel.' },
    'mail.deletionCancelled': { title: 'The deletion cancelled mail', text: 'Sent when the person cancels the deletion of their account.' },
    'mail.deleted': { title: 'The account deleted mail', text: 'Sent thirty days after the deletion was asked for, as the account is deleted: the last mail to that address.' },
    'mail.newVersion': {
      title: 'Announcements: the "New version" template',
      text: 'The words a new announcement starts from, {project} filled with the site chosen - changed there before it is sent.',
    },
    'mail.newRelease': { title: 'Announcements: the "New release" template', text: 'The same, for a new anotoki site.' },
    'mail.newSurvey': { title: 'Announcements: the "New survey" template', text: 'The same, for a survey about a site.' },
    'mail.news': { title: 'The end of every announcement', text: "Why the person gets it, and the link that stops the news - the link is the IAM's to put in." },
  },
  icon: 'mail',
};

/** How many accounts have the language as theirs: the IAM's own field of a language (`accounts`). */
function accountsOf(language: AdminLanguage | LanguageDeleted): number {
  const accounts = language['accounts'];
  return typeof accounts === 'number' ? accounts : 0;
}

/** "1 account has" / "3 accounts have" - for the sentences that say whose language it is. */
function accountsHave(count: number): string {
  return count === 1 ? '1 account has' : `${count} accounts have`;
}

/**
 * The admin panel's Translations and Languages pages - the anotoki family's
 * (@anotoki/lib/translations/admin, on lazy routes: admin.routes.ts) - as the
 * IAM words them: for the IAM's administrators alone (the server checks it
 * again: ADMIN of the IAM itself), its mails as blocks of their own, how many
 * accounts have each language (and go back to English with a language
 * deleted), and a failure in the admin panel's own words.
 */
export function localizationPages(session: SessionService): TranslationsAdminConfig {
  return {
    allows: { strings: () => session.isAdmin(), languages: () => session.isAdmin() },
    routes: { translations: '/admin/translations', languages: '/admin/languages', migrations: MIGRATIONS_ROUTE },
    siteName: 'the IAM',
    leads: {
      translations: 'What the IAM itself says - on the sign-in pages, on the account pages and in its mails - in every language. This admin panel stays English.',
      languages: "The languages the IAM's pages and mails can be read in. A new one reads in English until its strings are written, key by key.",
    },
    groups: [MAILS],
    languageNotes: {
      row: (language) => {
        const accounts = accountsOf(language);
        return accounts === 0 ? 'No account has it' : `${accountsHave(accounts)} it`;
      },
      beforeDelete: (language) => {
        const accounts = accountsOf(language);
        return accounts === 0
          ? 'No account has it as its language.'
          : `${accountsHave(accounts)} it as ${accounts === 1 ? 'its' : 'their'} language, and ${accounts === 1 ? 'goes' : 'go'} back to English.`;
      },
      afterDelete: (_language, answer) => {
        const accounts = accountsOf(answer);
        return accounts > 0 ? `${accounts === 1 ? '1 account went' : `${accounts} accounts went`} back to English.` : null;
      },
    },
    failureText: (failure) => adminErrorText(failure),
  };
}
