import { DOCUMENT, inject } from '@angular/core';
import { ANOTOKI_LEGAL_CONFIG, AnotokiLegalConfig, LegalFacts } from '@anotoki/lib/legal';
import { DEVICE_TOKEN_KEY } from '../auth/device-token';
import { TranslationService } from '../i18n/translation.service';
import { LANGUAGE_STORAGE } from '../i18n/translations.config';
import { THEME_STORAGE_KEY } from '../kit/kit.config';

/**
 * The day the IAM's facts last changed: the pages show the later of it and
 * the day the library's own words last did. Move it with any change to what
 * the IAM keeps or for how long - and the strings that say so (`legal.*`,
 * migration 020).
 */
export const IAM_LEGAL_UPDATED = '2026-10-14';

/**
 * Where a person deletes their account: the account page's Security page,
 * whose last part it is. A path of the IAM's own, so the pages' links go
 * there through the router - signed out, by way of the sign-in page.
 */
export const ACCOUNT_DELETION_PATH = '/account/security';

/** From asking for a deletion to the deletion (the backend's ACCOUNT_DELETION_DAYS, api/account_deletion.php). */
export const ACCOUNT_DELETION_DAYS = 30;

/**
 * The IAM's cookies, by the names its committed settings give them
 * (config/iam.php: `cookie_name`, `hint_cookie_name` - an installation's
 * iam.local.php may rename them, which a page cannot know). The session
 * cookie is `__Host-` before that name on https and the bare name on a
 * plain-http development machine (api/session.php); the device cookie is
 * named after the bare name everywhere (api/sign_in_devices.php).
 */
export const IAM_COOKIES = {
  session: 'anotoki_iam',
  device: 'anotoki_iam_device',
  signInHint: 'anotoki_signed_in',
} as const;

/**
 * The legal pages' configuration: the IAM's (core/legal/legal.config.ts - the
 * page's language, the database's words) with the facts the pages read. Their
 * frame provides it (layouts/legal-layout), so the facts - the code and its
 * strings' keys - load with the pages, never with the sign-in page.
 */
export function iamLegalPagesConfig(): AnotokiLegalConfig {
  const root = inject(ANOTOKI_LEGAL_CONFIG, { skipSelf: true });
  const i18n = inject(TranslationService);
  const secure = inject(DOCUMENT).location?.protocol === 'https:';
  return { ...root, facts: () => iamLegalFacts(i18n, secure) };
}

/**
 * The IAM's facts, in the page's language - read as a page is drawn, so a
 * change of language redraws them. Each must stay true of the code: the
 * tables (backend/migrations/iam), the housekeeping (api/housekeeping.php),
 * the deletion (api/account_deletion.php), the cookies (api/session.php,
 * api/sign_in_devices.php) and this frontend's storage. The Privacy page is
 * the one every anotoki site links to for the account (`/privacy`).
 *
 * Every address the pages link to is the code's - the account page here,
 * the operator, Cloudflare and Google in the library -, never a string's: a
 * rewording on the Translations page can never change where a link goes (the
 * 0.6.0 review). So are the cookies' and stored items' names, and the days.
 *
 * What the IAM leaves to the library's words: the error log (its 30 days -
 * iamErrorLog() sets none of its own) and counting visits (the family's
 * Umami, index.html: umami.anotoki.eu, the query and the fragment left out).
 * No visitors part - nothing here is used without an account but signing in
 * and up, which the attempts part says - and no feedback: the IAM takes
 * none, so its Contact page gives the address alone.
 */
export function iamLegalFacts(i18n: TranslationService, secure: boolean): LegalFacts {
  return {
    site: i18n.t('brand.account'),
    about: i18n.t('legal.about'),
    updated: IAM_LEGAL_UPDATED,
    account: { isIam: true, deleteUrl: ACCOUNT_DELETION_PATH, deletionDays: ACCOUNT_DELETION_DAYS },
    data: [
      { name: i18n.t('legal.account.name'), what: i18n.t('legal.account.what'), why: i18n.t('legal.account.why'), kept: i18n.t('legal.account.kept') },
      { name: i18n.t('legal.twoFactor.name'), what: i18n.t('legal.twoFactor.what'), why: i18n.t('legal.twoFactor.why'), kept: i18n.t('legal.twoFactor.kept') },
      { name: i18n.t('legal.google.name'), what: i18n.t('legal.google.what'), why: i18n.t('legal.google.why'), kept: i18n.t('legal.google.kept') },
      { name: i18n.t('legal.sessions.name'), what: i18n.t('legal.sessions.what'), why: i18n.t('legal.sessions.why'), kept: i18n.t('legal.sessions.kept') },
      { name: i18n.t('legal.attempts.name'), what: i18n.t('legal.attempts.what'), why: i18n.t('legal.attempts.why'), kept: i18n.t('legal.attempts.kept') },
      { name: i18n.t('legal.remembered.name'), what: i18n.t('legal.remembered.what'), why: i18n.t('legal.remembered.why'), kept: i18n.t('legal.remembered.kept') },
      { name: i18n.t('legal.browsers.name'), what: i18n.t('legal.browsers.what'), why: i18n.t('legal.browsers.why'), kept: i18n.t('legal.browsers.kept') },
      { name: i18n.t('legal.sites.name'), what: i18n.t('legal.sites.what'), why: i18n.t('legal.sites.why'), kept: i18n.t('legal.sites.kept') },
      { name: i18n.t('legal.mails.name'), what: i18n.t('legal.mails.what'), why: i18n.t('legal.mails.why'), kept: i18n.t('legal.mails.kept') },
      { name: i18n.t('legal.news.name'), what: i18n.t('legal.news.what'), why: i18n.t('legal.news.why'), kept: i18n.t('legal.news.kept') },
      { name: i18n.t('legal.approvals.name'), what: i18n.t('legal.approvals.what'), why: i18n.t('legal.approvals.why'), kept: i18n.t('legal.approvals.kept') },
      { name: i18n.t('legal.deletion.name'), what: i18n.t('legal.deletion.what'), why: i18n.t('legal.deletion.why'), kept: i18n.t('legal.deletion.kept') },
    ],
    // The IAM's audit log keeps people's own security changes too, and the address (audit(), requestIp()).
    auditLog: { what: i18n.t('legal.audit.what'), why: i18n.t('legal.audit.why'), kept: i18n.t('legal.audit.kept') },
    // Encrypted under the master key, never restored here; 14 daily, 5 before an update, by hand until deleted (iamBackups()).
    backups: { encrypted: true, what: i18n.t('legal.backups.what'), why: i18n.t('legal.backups.why'), kept: i18n.t('legal.backups.kept') },
    storage: [
      { preset: 'signInHint', name: IAM_COOKIES.signInHint },
      {
        name: secure ? `__Host-${IAM_COOKIES.session}` : IAM_COOKIES.session,
        kind: 'cookie',
        purpose: i18n.t('legal.storage.session.purpose'),
        kept: i18n.t('legal.storage.session.kept'),
      },
      { name: IAM_COOKIES.device, kind: 'cookie', purpose: i18n.t('legal.storage.device.purpose'), kept: i18n.t('legal.storage.device.kept') },
      { name: DEVICE_TOKEN_KEY, kind: 'local', purpose: i18n.t('legal.storage.remembered.purpose'), kept: i18n.t('legal.storage.remembered.kept') },
      { preset: 'theme', name: THEME_STORAGE_KEY },
      { preset: 'language', name: `${LANGUAGE_STORAGE.language}, ${LANGUAGE_STORAGE.cache}` },
      { name: LANGUAGE_STORAGE.language, kind: 'session', purpose: i18n.t('legal.storage.tabLanguage.purpose'), kept: i18n.t('legal.storage.untilTabClosed') },
      { name: LANGUAGE_STORAGE.drafts, kind: 'session', purpose: i18n.t('legal.storage.drafts.purpose'), kept: i18n.t('legal.storage.untilTabClosed') },
    ],
    processors: [
      // "To be named" until the owner names the hosting company and the mail service (PHP's mail() on the host, config/mail.php).
      { preset: 'hosting' },
      { preset: 'cloudflare' },
      // Google's button is loaded from Google wherever it is drawn: the library's words would say "only if you choose".
      { preset: 'google', role: i18n.t('legal.google.role') },
      { preset: 'mail', role: i18n.t('legal.mail.role') },
    ],
  };
}
