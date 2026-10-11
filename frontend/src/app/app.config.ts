import { provideHttpClient, withFetch, withInterceptors } from "@angular/common/http";
import { ApplicationConfig, DOCUMENT, inject, provideAppInitializer, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from "@angular/core";
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from "@angular/router";
import { provideAnotokiAdmin } from "@anotoki/lib/admin";
import { provideAnotokiMigrations, siteStatusInterceptor } from "@anotoki/lib/migrations";
import { ThemeService, provideAnotokiShell } from "@anotoki/lib/shell";
import { provideAnotokiTranslations } from "@anotoki/lib/translations";
import { provideAnotokiUi } from "@anotoki/lib/ui/config";
import { routes } from "./app.routes";
import { iamAdminToolsConfig } from "./core/admin/admin-tools.config";
import { SessionService } from "./core/auth/session.service";
import { iamInterceptor } from "./core/http/iam.interceptor";
import { IamTitleStrategy } from "./core/i18n/title.strategy";
import { TranslationService } from "./core/i18n/translation.service";
import { iamTranslationsConfig, startTranslations } from "./core/i18n/translations.config";
import { iamShellConfig, iamUiConfig } from "./core/kit/kit.config";
import { iamMigrationsConfig } from "./core/migrations/migrations.config";

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    // Query parameters (`?request=`, `?next=`, `?token=`) and route parameters
    // arrive as component inputs, so pages read them as signals.
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: "enabled" })),
    // The IAM's own interceptor outermost; the family's (@anotoki/lib) inside
    // it, nearest the server, where an answer still is the HttpErrorResponse
    // it reads: a 503 `not_set_up` (or `update_pending`) tells SiteStatus.
    provideHttpClient(withFetch(), withInterceptors([iamInterceptor, siteStatusInterceptor])),
    // The family's translations (@anotoki/lib/translations): the pages' words
    // from the database, the language, the admin panel's Translations and
    // Languages pages (core/i18n/translations.config.ts).
    provideAnotokiTranslations(iamTranslationsConfig),
    // The family's UI kit and its shell (@anotoki/lib): the IAM's words and
    // icons for the kit, its theme and languages for the shell
    // (core/kit/kit.config.ts).
    provideAnotokiUi(iamUiConfig),
    provideAnotokiShell(iamShellConfig),
    // The family's migrations module: the admin panel's Migrations page and
    // its banner, the not-set-up page. No gate and no check at boot - the IAM
    // keeps working while an update waits (core/migrations/migrations.config.ts).
    provideAnotokiMigrations(iamMigrationsConfig),
    // The family's admin tools: the admin panel's Error log, Audit log and
    // Backups pages, English, for the IAM's ADMIN alone; what only the panel
    // says and does is handed over by its routes (core/admin/admin-tools.config.ts).
    provideAnotokiAdmin(iamAdminToolsConfig),
    { provide: TitleStrategy, useClass: IamTitleStrategy },
    // The theme applies itself on construction; asking for it here makes that
    // happen before the first page renders, not on first use. The words are
    // waited for: the first page is drawn once its language's strings are in
    // memory - or, after three seconds at most, in the compiled English.
    // The start never rejects, so a server that cannot be reached still leaves
    // a site that starts and says so.
    provideAppInitializer(() => {
      inject(ThemeService);
      return startTranslations(inject(SessionService), inject(TranslationService), inject(DOCUMENT).location?.pathname ?? "/");
    }),
  ],
};
