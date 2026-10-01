import { provideHttpClient, withFetch, withInterceptors } from "@angular/common/http";
import { ApplicationConfig, inject, provideAppInitializer, provideBrowserGlobalErrorListeners, provideZonelessChangeDetection } from "@angular/core";
import { TitleStrategy, provideRouter, withComponentInputBinding, withInMemoryScrolling } from "@angular/router";
import { routes } from "./app.routes";
import { iamInterceptor } from "./core/http/iam.interceptor";
import { IamTitleStrategy } from "./core/i18n/title.strategy";
import { TranslationService } from "./core/i18n/translation.service";
import { ThemeService } from "./core/theme/theme.service";

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideZonelessChangeDetection(),
    // Query parameters (`?request=`, `?next=`, `?token=`) and route parameters
    // arrive as component inputs, so pages read them as signals.
    provideRouter(routes, withComponentInputBinding(), withInMemoryScrolling({ scrollPositionRestoration: "enabled" })),
    provideHttpClient(withFetch(), withInterceptors([iamInterceptor])),
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
