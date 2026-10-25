import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { AnotokiAgoPipe, AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { ApprovalQueue, PendingAccount } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';

/**
 * /admin/approvals - the accounts waiting for an administrator's yes or no.
 *
 * While Settings -> New accounts need approval is on, an account made with
 * the registration form or with Google signs in nowhere until it is approved
 * here (or on its own page under Users). Approving mails the person once
 * their address is confirmed; declining switches the account off - nothing is
 * deleted - and mails nobody. An account whose address is not confirmed is not
 * declined (the server refuses it too): its registrant may not own the
 * address, and its owner takes such an account back by a reset link or
 * Google - which nothing does for one switched off.
 */
@Component({
  selector: 'app-approvals',
  imports: [
    RouterLink,
    AnotokiAgoPipe,
    AnotokiDatePipe,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    PageHeaderComponent,
    SpinnerComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './approvals.component.html',
  styleUrl: './approvals.component.scss',
})
export class ApprovalsComponent {
  private readonly _adminApi = inject(AdminApi);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly queue = signal<ApprovalQueue | null>(null);
  protected readonly loadError = signal<string | null>(null);
  /** The account a decision is on its way for. */
  protected readonly busy = signal<number | null>(null);
  /** Some account listed cannot be declined yet: the page says why under the list. */
  protected readonly anyUnconfirmed = computed(() => (this.queue()?.accounts ?? []).some((account) => !account.email_confirmed));

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this._adminApi.approvals().subscribe({
      next: (queue) => this.queue.set(queue),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected approve(account: PendingAccount): void {
    this.busy.set(account.user_id);
    this._adminApi.approve(account.user_id).subscribe({
      next: (decision) => {
        this.busy.set(null);
        this._toast.success(
          decision.mailed
            ? `${account.username} is approved, and was emailed.`
            : account.email_confirmed
              ? `${account.username} is approved. The email could not be sent - tell them yourself.`
              : `${account.username} is approved, and is signed in by their confirmation link.`,
        );
        this.load();
      },
      error: (error: ApiError) => this._refused(error),
    });
  }

  protected async decline(account: PendingAccount): Promise<void> {
    const sure = await this._confirm.ask({
      title: `Decline ${account.username}?`,
      message: 'The account is switched off: nothing is deleted, and its address stays taken. Switching it on again (Users → the person) puts it back here. Nobody is emailed.',
      confirmLabel: 'Decline',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.busy.set(account.user_id);
    this._adminApi.decline(account.user_id).subscribe({
      next: () => {
        this.busy.set(null);
        this._toast.success(`${account.username} is declined and switched off.`);
        this.load();
      },
      error: (error: ApiError) => this._refused(error),
    });
  }

  private _refused(error: ApiError): void {
    this.busy.set(null);
    this._toast.error(adminErrorText(error));
    // Another administrator may have decided first: show what is there now.
    this.load();
  }
}
