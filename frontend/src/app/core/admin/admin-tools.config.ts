import { inject } from '@angular/core';
import { AdminFailure, AnotokiAdminConfig } from '@anotoki/lib/admin';
import { SessionService } from '../auth/session.service';
import { formatAppDate } from '../i18n/app-date.pipe';
import { TranslationService } from '../i18n/translation.service';
import { MIGRATIONS_ROUTE } from '../migrations/migrations.config';

/**
 * What only the admin panel says and does for the admin tools' pages - handed
 * over by its routes as it loads (pages/admin/admin-tools.ts), so none of it
 * is in a sign-in page's first load.
 */
export interface AdminToolsPanel {
  /** A refusal in the panel's words (adminErrorText). */
  failureText(failure: AdminFailure): string;
  /** Signs out, then the sign-in page, back to this page: the proof a session alone cannot give. */
  signInAgain(): void;
  /** Why the IAM restores no backup in place, after the Backups page's own sentence. */
  restoreOffText: string;
}

let panel: AdminToolsPanel | null = null;

/** From the admin panel's routes (their environment initializer), which load before any of the pages can be made; null: none. */
export function useAdminToolsPanel(part: AdminToolsPanel | null): void {
  panel = part;
}

/**
 * How the IAM plugs into the anotoki family's admin tools
 * (`@anotoki/lib/admin`, whose server half is the backend's anotoki/lib): the
 * admin panel's Error log, Audit log, Feedback and Backups pages - the
 * family's, English as the whole panel, each on a lazy route
 * (pages/admin/admin.routes.ts).
 * `provideAnotokiAdmin()` runs it in an injection context, the first time a
 * page asks for it.
 *
 * - Whose they are: the session's account, ADMIN of the IAM itself
 *   (`is_admin`, which a pending deletion of the account's own switches off).
 *   The admin layout shows them to nobody else anyway.
 * - Their requests go through the IAM's HttpClient, so its interceptor puts
 *   the CSRF header on every write and sends an ended session to the sign-in
 *   page; a refusal comes back reshaped (ApiError), which the pages read.
 * - The proof before a backup is made, downloaded or deleted is the IAM's own
 *   (the backend's accountProof()): the password, asked in the page's dialog,
 *   or - for an account without one - a sign-in of the last fifteen minutes,
 *   whose refusal offers "Sign in again" (FreshSignInService).
 * - A person the logs or a feedback message name opens on the Users page;
 *   dates as the account writes them (`appDate`); the Migrations page while a
 *   table waits.
 */
export function iamAdminToolsConfig(): AnotokiAdminConfig {
  const session = inject(SessionService);
  const i18n = inject(TranslationService);
  return {
    isAdmin: session.isAdmin,
    siteName: 'the IAM',
    migrationsRoute: MIGRATIONS_ROUTE,
    personLink: (id) => ['/admin/users', id],
    formatDate: (iso) => formatAppDate(iso, 'datetime', i18n.locale(), session.user()?.preferences ?? null),
    failureText: (failure) => panel?.failureText(failure),
    signInAgain: () => panel?.signInAgain(),
    // Read as the Backups page is made - after the panel's routes handed it over.
    get restoreOffText(): string | null {
      return panel?.restoreOffText ?? null;
    },
  };
}
