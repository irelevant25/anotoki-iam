import { inject } from '@angular/core';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AdminToolsPanel } from '../../shared/admin/admin-tools.config';
import { ApiError } from '../../api/api-error';
import { FreshSignInService } from '../../shared/auth/fresh-sign-in';
import { adminErrorText } from './shared/admin-errors';

/**
 * Why the Backups page offers no Restore on the IAM, after its own sentence
 * (a backup is restored offline, with the library's command line): the
 * owner's decision of 2026-10-11 (docs/decisions.md).
 */
export const RESTORE_OFF_TEXT =
  "On the IAM it stays off until a restore can end every session and token and delete again every account deleted since - as it is, one would bring back ended sessions and refresh tokens, old passwords, roles taken away and deleted accounts. A backup opens only with this IAM's master key (config/secret.local.php): keep a copy of it off the server.";

/**
 * The admin panel's part of the family's admin tools (shared/admin/admin-tools.config.ts),
 * handed over by the panel's routes as they load - run in their injection context:
 *
 * - a refusal in the panel's words, as every other admin page says one;
 * - "Sign in again", for the proof a session alone cannot give: out, and the
 *   sign-in page, back to the page it was asked on (FreshSignInService). The
 *   page itself asks nothing more; should signing out fail, a toast says so;
 * - why restoring is off here.
 */
export function adminToolsPanel(): AdminToolsPanel {
  const freshSignIn = inject(FreshSignInService);
  const toast = inject(ToastService);
  return {
    failureText: (failure) => adminErrorText(failure),
    signInAgain: () => {
      freshSignIn.signInAgain().subscribe({
        error: (error: ApiError) => toast.error(`Could not sign you out to sign in again. ${adminErrorText(error)}`),
      });
    },
    restoreOffText: RESTORE_OFF_TEXT,
  };
}
