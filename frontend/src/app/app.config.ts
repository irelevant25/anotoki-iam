import { provideHttpClient, withFetch, withInterceptors } from "@angular/common/http";
import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from "@angular/core";
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from "@angular/router";
import { provideAnotokiMigrations, siteStatusInterceptor } from "@anotoki/lib/migrations";
import { routes } from "./app.routes";
import { iamInterceptor } from "./core/http/iam.interceptor";
import { IamTitleStrategy } from "./core/i18n/title.strategy";
import { TranslationService } from "./core/i18n/translation.service";
import { iamMigrationsConfig } from "./core/migrations/migrations.config";
import { ThemeService } from "./core/theme/theme.service";

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
    // The family's migrations module: the admin panel's Migrations page and
    // its banner, the not-set-up page. No gate and no check at boot - the IAM
    // keeps working while an update waits (core/migrations/migrations.config.ts).
    provideAnotokiMigrations(iamMigrationsConfig),
    { provide: TitleStrategy, useClass: IamTitleStrategy },
    // The theme applies itself on construction; asking for it here makes that
    // happen before the first page renders, not on first use. The words are
    // waited for: the first page is drawn once its language's strings are in
    // memory - or, after three seconds at most, in the compiled English.
    // init() never rejects, so a server that cannot be reached still leaves a
    // site that starts and says so.
    provideAppInitializer(() => {
      inject(ThemeService);
      return inject(TranslationService).init();
    }),
  ],
};
