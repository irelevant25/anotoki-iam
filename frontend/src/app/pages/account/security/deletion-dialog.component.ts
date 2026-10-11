import { ChangeDetectionStrategy, Component, inject, output } from '@angular/core';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { AccountUser } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { formatAppDate } from '../../../core/i18n/app-date.pipe';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { ProofDialogComponent } from './proof-dialog.component';
import { SECURITY_ANCHORS } from './security-anchors';

/**
 * Asking for the account to be deleted (`POST /api/account/deletion`), with
 * the proof every such thing asks for (ProofDialogComponent): the password
 * where it is a way in, else a sign-in of the last fifteen minutes - the way
 * to a fresh one comes back to the Security page's card.
 *
 * Asked, the account is closed at once and deleted thirty days later: the
 * session's account carries the day, the account pages' notice says it and
 * offers to cancel, and the Security page's card goes.
 */
@Component({
  selector: 'app-deletion-dialog',
  imports: [TPipe, ProofDialogComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './deletion-dialog.component.html',
  styleUrl: './deletion-dialog.component.scss',
})
export class DeletionDialogComponent {
  /** True once the deletion is asked for. */
  readonly closed = output<boolean>();

  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);

  protected readonly anchor = SECURITY_ANCHORS.deletion;
  /** The account it answers with is the session's - unless Sign out came first (setFrom()). */
  protected readonly send = (password: string | undefined) => this._session.setFrom(this._accountApi.requestDeletion(password));
  protected readonly explain = (error: ApiError): string | null => (error.code === 'last_admin' ? this._i18n.t('deletion.lastAdmin') : null);

  protected requested(user: AccountUser): void {
    const date = formatAppDate(user.deletion_due_at, 'date', this._i18n.locale(), user.preferences);
    // The mail that says how to keep the account goes to a confirmed address
    // only: so does the toast that says it was sent.
    this._toast.show(this._i18n.t(user.email_confirmed ? 'deletion.requested' : 'deletion.pendingTitle', { date }), 'success', 9000);
    this.closed.emit(true);
  }

  protected refused(error: ApiError): void {
    if (error.code === 'deletion_already_requested') {
      // Asked for already, in another tab: the account as it is now brings
      // the notice that says when, and the way to cancel it.
      this._session.refresh().subscribe({ error: () => undefined });
      this.closed.emit(false);
    }
  }
}
