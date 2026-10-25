import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, DOCUMENT, inject, provideAppInitializer, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from '@angular/core';
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';
import { provideAnotokiAdmin } from '@anotoki/lib/admin';
import { provideAnotokiErrorReport } from '@anotoki/lib/error-report';
import { provideAnotokiFeedback } from '@anotoki/lib/feedback';
import { provideAnotokiLegal } from '@anotoki/lib/legal';
import { provideAnotokiMigrations, siteStatusInterceptor } from '@anotoki/lib/migrations';
import { ThemeService, provideAnotokiShell } from '@anotoki/lib/shell';
import { SiteSettings, provideAnotokiSiteSettings } from '@anotoki/lib/site-settings';
import { provideAnotokiTranslations } from '@anotoki/lib/translations';
import { provideAnotokiUi } from '@anotoki/lib/ui/config';
import { routes } from './app.routes';
import { iamAdminToolsConfig } from './shared/admin/admin-tools.config';
import { SessionService } from './shared/auth/session.service';
import { Changelog } from './shared/changelog/changelog.service';
import { iamFeedbackConfig } from './shared/feedback/feedback.config';
import { iamInterceptor } from './shared/http/iam.interceptor';
import { IamTitleStrategy } from './shared/i18n/title.strategy';
import { TranslationService } from './shared/i18n/translation.service';
import { iamTranslationsConfig, startTranslations } from './shared/i18n/translations.config';
import { iamShellConfig, iamUiConfig } from './shared/kit/kit.config';
import { iamLegalConfig } from './shared/legal/legal.config';
import { iamMigrationsConfig } from './shared/migrations/migrations.config';
import { iamSiteSettingsConfig } from './shared/site-settings/site-settings.config';

export const appConfig: ApplicationConfig = {
  providers: [
    // The pages' own errors (the owner's decision of 2026-10-11: every
    // anotoki site's error log holds its pages' errors too). Each still goes
    // to the console as ever, and is reported - scrubbed, once, five a page
    // load at most - to `POST /api/client-errors` (the library's route,
    // routes/login.php) through the app's HttpClient: the IAM's interceptor
    // gives it the CSRF header every write carries, and moves no page for its
    // failure (shared/http/iam.interceptor.ts). With the browser's global
    // listeners, the errors outside Angular's own code arrive too.
    provideBrowserGlobalErrorListeners(),
    provideAnotokiErrorReport(),
    provideZonelessChangeDetection(),
    // Query parameters (`?request=`, `?next=`, `?token=`) and route parameters
    // arrive as component inputs, so pages read them as signals.
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: 'enabled' })),
    // The IAM's own interceptor outermost; the family's (@anotoki/lib) inside
    // it, nearest the server, where an answer still is the HttpErrorResponse
    // it reads: a 503 `not_set_up` (or `update_pending`) tells SiteStatus.
    provideHttpClient(withFetch(), withInterceptors([iamInterceptor, siteStatusInterceptor])),
    // The family's translations (@anotoki/lib/translations): the pages' words
    // from the database, the language, the admin panel's Translations and
    // Languages pages (shared/i18n/translations.config.ts).
    provideAnotokiTranslations(iamTranslationsConfig),
    // The family's UI kit and its shell (@anotoki/lib): the IAM's words and
    // icons for the kit, its theme and languages for the shell
    // (shared/kit/kit.config.ts).
    provideAnotokiUi(iamUiConfig),
    provideAnotokiShell(iamShellConfig),
    // The family's migrations module: the admin panel's Migrations page and
    // its banner, the not-set-up page. No gate and no check at boot - the IAM
    // keeps working while an update waits (shared/migrations/migrations.config.ts).
    provideAnotokiMigrations(iamMigrationsConfig),
    // The family's admin tools: the admin panel's Error log, Audit log,
    // Feedback and Backups pages, English, for the IAM's ADMIN alone; what
    // only the panel says and does is handed over by its routes
    // (shared/admin/admin-tools.config.ts).
    provideAnotokiAdmin(iamAdminToolsConfig),
    // The family's legal pages - Terms, Privacy, Contact, on lazy routes - and
    // the links to them in the family's footer (@anotoki/lib/footer, which has
    // no configuration of its own - its links read this one; the IAM gives it
    // its version and What's new, from public/changelog.json - shared/changelog -,
    // and Feedback): the library's words around the IAM's facts,
    // every address the pages link to in the code (shared/legal/legal.config.ts;
    // the facts, which only the pages read, come with their frame:
    // sites/main/legal/legal-facts.ts, sites/main/legal/legal-layout).
    // The IAM's Privacy page is the one every anotoki site links to.
    provideAnotokiLegal(iamLegalConfig),
    // The family's feedback form (the owner's decision of 2026-10-19), which
    // the footer opens under every page and the Contact page too - its code
    // with the first press: the page's language, the database's words, the
    // session's account and its address (shared/feedback/feedback.config.ts).
    provideAnotokiFeedback(iamFeedbackConfig),
    // The family's site settings (@anotoki/lib/site-settings, anotoki-lib
    // 0.11.0): the announcement alone - the one part the IAM offers -, a bar
    // across the top of the sign-in, account and legal pages' frames, never
    // the admin panel's (shared/site-settings/site-settings.config.ts).
    provideAnotokiSiteSettings(iamSiteSettingsConfig),
    { provide: TitleStrategy, useClass: IamTitleStrategy },
    // The theme applies itself on construction; asking for it here makes that
    // happen before the first page renders, not on first use. The words are
    // waited for: the first page is drawn once its language's strings are in
    // memory - or, after three seconds at most, in the compiled English.
    // The start never rejects, so a server that cannot be reached still leaves
    // a site that starts and says so. The IAM's versions (public/changelog.json,
    // shared/changelog) are asked for beside it, once a page load and never
    // waited for: the footer's version is there long before the words are. So
    // is the announcement (GET /api/site-settings), never waited for either:
    // the IAM governs no page by its settings, so nothing has to be known
    // before the first page is drawn, and a sign-in page waits on nothing it
    // can do without. It is usually there before the words, in the bar above
    // every frame but the admin panel's.
    provideAppInitializer(() => {
      inject(ThemeService);
      void inject(Changelog).load();
      void inject(SiteSettings).load();
      return startTranslations(inject(SessionService), inject(TranslationService), inject(DOCUMENT).location?.pathname ?? '/');
    }),
  ],
};
