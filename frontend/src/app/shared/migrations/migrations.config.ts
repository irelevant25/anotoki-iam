import { Injector, inject } from '@angular/core';
import { Router } from '@angular/router';
import { AnotokiMigrationsConfig, SiteStatusWords } from '@anotoki/lib/migrations';
import { TranslationService as LibraryTranslations, siteStatusWords } from '@anotoki/lib/translations';
import { adminDateTime } from '../admin/admin-dates';
import { SessionService } from '../auth/session.service';
import { TranslationService } from '../i18n/translation.service';

/** The admin panel's Migrations page: the route that shows the module's page. */
export const MIGRATIONS_ROUTE = '/admin/migrations';

/**
 * How the IAM plugs into the anotoki family's migrations module
 * (`@anotoki/lib/migrations`, whose server half is the backend's anotoki/lib):
 * its status, the admin panel's Migrations page, the one-line banner, the
 * not-set-up page's words. `provideAnotokiMigrations()` runs it in an injection
 * context.
 *
 * The IAM does not block while an update waits - it is the door every site
 * signs in through - so it puts no `<anotoki-site-gate>` around its pages and
 * asks nothing at boot: its administrators see `<anotoki-update-banner>` in
 * the admin panel, and its features check their own tables as ever.
 *
 * - Who: the session's account, ADMIN of the IAM itself (`is_admin`, which a
 *   pending deletion of the account's own switches off).
 * - Signing in: the IAM's own sign-in page, coming back to the page it was
 *   asked from. The Router is taken when it is needed, not here: this runs at
 *   the first request, and the services the Router builds must not wait for
 *   it (the TranslationService says why).
 * - The words: the database's - the library's own words for the status page
 *   (`anotoki.siteStatus.*`, reworded on the Translations page like any
 *   other; the module's built-in English and Slovak where there is none) -
 *   and over them the IAM's own words for its not-set-up page (`notSetUp.*`),
 *   in the language on the page.
 * - Dates: as the account writes them (the family's, handed over by the
 *   admin panel's routes: shared/admin/admin-dates.ts).
 * - After an Apply that leaves nothing to apply: the strings are read again -
 *   a migration may have brought some.
 */
export function iamMigrationsConfig(): AnotokiMigrationsConfig {
  const session = inject(SessionService);
  const i18n = inject(TranslationService);
  // The same service, typed as the library's own helpers take it.
  const library = inject(LibraryTranslations);
  const injector = inject(Injector);

  return {
    statusUrl: '/api/site-status',
    apiBase: '/api/admin/migrations',
    migrationsRoute: MIGRATIONS_ROUTE,
    isAdmin: session.isAdmin,
    isSignedIn: session.signedIn,
    signIn: () => {
      const router = injector.get(Router);
      return router.navigate(['/login'], { queryParams: { next: router.url } });
    },
    language: i18n.effectiveLanguage,
    // A getter, read by the module's page whenever it draws its words: they
    // follow the language and its strings as they arrive. `t()` reads what is
    // on the page - the compiled English while no bundle is in memory, which is
    // how an IAM that is not set up always is - so the words are keyed by the
    // language they are in, never by one wished for. The not-set-up page's
    // words are the IAM's own keys, handed over as the site's overrides. Since
    // anotoki-lib 0.7.2 the language with no bundle in memory is the reader's
    // (a Slovak reader's `sk`) while `t()` still reads the compiled English:
    // then the IAM's words are keyed `en`, and the library's built-in words of
    // the reader's language show in their place.
    get words(): Record<string, Partial<SiteStatusWords>> {
      return siteStatusWords(library, {
        [library.ready() ? i18n.effectiveLanguage() : 'en']: {
          notSetUpTitle: i18n.t('notSetUp.title'),
          notSetUpText: i18n.t('notSetUp.text'),
          openSetup: i18n.t('notSetUp.open'),
          tryAgain: i18n.t('notSetUp.check'),
        },
      });
    },
    formatDate: adminDateTime,
    onUpToDate: () => i18n.reload(),
  };
}
