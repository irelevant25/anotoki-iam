import { inject } from '@angular/core';
import { AnotokiSiteSettingsConfig } from '@anotoki/lib/site-settings';
import { SessionService } from '../auth/session.service';

/**
 * Where the browser keeps a mark of the announcement its reader hid - a
 * fingerprint of its kind and words, never the words - until they change: a
 * name of the IAM's own, as its other stored items have (`anotoki-iam:theme`,
 * `anotoki-iam:lang`). The Privacy page names it (the legal facts' storage
 * preset `announcement`, sites/main/legal/legal-facts.ts).
 */
export const ANNOUNCEMENT_STORAGE_KEY = 'anotoki-iam:announcement';

/**
 * How the IAM plugs into the anotoki family's site settings
 * (@anotoki/lib/site-settings, anotoki-lib 0.11.0; the backend's
 * iamSiteSettings(), api/site_settings.php): the ANNOUNCEMENT ALONE - the bar
 * (`<anotoki-announcement>`) above the sign-in pages', the account pages' and
 * the legal pages' frames, never the admin panel's -, read once as the site
 * starts and never waited for (app.config.ts). The IAM offers no Getting in
 * and no Page access (the owner's decision: closed for maintenance, the IAM
 * would stop every site's sign-in), so it has no guard, no administrators'
 * notice and no staff route.
 *
 * Who the reader is matters only to the parts the IAM does not offer (a page
 * kept for some readers, a notice for the administrators): the session's
 * account, ADMIN where it is the IAM's.
 */
export function iamSiteSettingsConfig(): AnotokiSiteSettingsConfig {
  const session = inject(SessionService);
  return {
    roles: () => (session.isAdmin() ? ['ADMIN'] : []),
    signedIn: () => session.signedIn(),
    storageKey: ANNOUNCEMENT_STORAGE_KEY,
  };
}
