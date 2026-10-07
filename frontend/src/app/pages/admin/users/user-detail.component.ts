import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, linkedSignal, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import { Observable, catchError, forkJoin, of, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminUser, AdminUserDetail, AdminUserFilters, PersonalToken, SessionEntry, SetPasswordResponse } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { TPipe } from '../../../core/i18n/t.pipe';
import { deviceLabel } from '../../../core/util/user-agent';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent, BadgeTone } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { ConfirmService } from '../../../ui/confirm/confirm.service';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { sessionMethodKey, sessionStateKey } from '../../account/session-labels';
import { adminErrorText } from '../admin-errors';
import { EditUserDialogComponent } from './edit-user-dialog.component';
import { RolePickerComponent, grantFromKey, grantKey } from './role-picker.component';
import { SetPasswordDialogComponent } from './set-password-dialog.component';

/**
 * /admin/users/:id - one person, gathered from every table that knows them.
 *
 * The self-lockout rules are the backend's (an admin cannot switch themselves
 * off or take the IAM's ADMIN from themselves, and the last admin stays); the
 * page shows the refusal where the action was, in the server's own words.
 *
 * An account waiting for an administrator's approval is approved or declined
 * here as on the Approvals page.
 *
 * An account's deletion is its owner's alone to ask for and cancel: the page
 * only says when it is due. Once done, the account is a placeholder that
 * changes no more - its buttons stay, switched off, under a note that says
 * why.
 */
@Component({
  selector: 'app-user-detail',
  imports: [
    RouterLink,
    AppDatePipe,
    TPipe,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    SpinnerComponent,
    EditUserDialogComponent,
    RolePickerComponent,
    SetPasswordDialogComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './user-detail.component.html',
  styleUrl: './user-detail.component.scss',
})
export class UserDetailComponent {
  /** The `:id` route parameter. */
  readonly id = input.required<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);
  private readonly _session = inject(SessionService);

  protected readonly userId = computed(() => Number(this.id()));
  protected readonly detail = signal<AdminUserDetail | null>(null);
  protected readonly filters = signal<AdminUserFilters | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly actionError = signal<string | null>(null);
  protected readonly busyAction = signal<string | null>(null);
  protected readonly dialog = signal<'edit' | 'password' | null>(null);

  protected readonly account = computed(() => this.detail()?.account ?? null);
  protected readonly isSelf = computed(() => this.account()?.id === this._session.user()?.id);
  /** Deleted at its owner's request: a placeholder the server refuses every change to (409 `account_anonymized`), so the page offers none. */
  protected readonly anonymized = computed(() => !!this.account()?.anonymized_at);

  // ── Roles ──────────────────────────────────────────────────────────────────
  private readonly _savedRoles = computed(() => (this.account()?.roles ?? []).map(grantKey).sort());
  protected readonly roles = linkedSignal(() => this._savedRoles());
  protected readonly rolesChanged = computed(() => [...this.roles()].sort().join('|') !== this._savedRoles().join('|'));
  protected readonly savingRoles = signal(false);
  protected readonly rolesError = signal<string | null>(null);

  protected readonly methodKey = sessionMethodKey;
  protected readonly stateKey = sessionStateKey;

  constructor() {
    toObservable(this.userId)
      .pipe(
        tap(() => {
          this.loading.set(true);
          this.loadError.set(null);
        }),
        switchMap((id) =>
          forkJoin({ detail: this._adminApi.userDetail(id), filters: this._adminApi.userFilters() }).pipe(
            catchError((error: ApiError) => {
              this.loadError.set(error.status === 404 ? 'There is no user with this id.' : adminErrorText(error));
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(inject(DestroyRef)),
      )
      .subscribe((result) => {
        if (result) {
          this.detail.set(result.detail);
          this.filters.set(result.filters);
        }
        this.loading.set(false);
      });
  }

  protected reload(): void {
    this._adminApi.userDetail(this.userId()).subscribe({
      next: (detail) => {
        this.detail.set(detail);
        this.loadError.set(null);
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  // ── Actions ────────────────────────────────────────────────────────────────

  protected edited(user: AdminUser | null): void {
    this.dialog.set(null);
    if (user) {
      this._toast.success('Saved.');
      this.reload();
    }
  }

  protected passwordSet(response: SetPasswordResponse | null): void {
    this.dialog.set(null);
    if (response) {
      this._toast.success(`${response.message}. ${response.note}`);
      this.reload();
    }
  }

  protected async toggleEnabled(): Promise<void> {
    const account = this.account();
    if (!account) {
      return;
    }
    const enable = account.disabled;
    const sure = await this._confirm.ask(
      enable
        ? { title: `Switch ${account.username} back on?`, message: 'They can sign in again straight away.', confirmLabel: 'Switch on' }
        : {
            title: `Switch off ${account.username}?`,
            message: 'Every session and refresh token of the account ends, on every site. Nothing is deleted - switching it back on restores it.',
            confirmLabel: 'Switch off',
            tone: 'danger',
          },
    );
    if (!sure) {
      return;
    }

    this._run('enabled', this._adminApi.setUserEnabled(account.id, enable), enable ? `${account.username} is switched on.` : `${account.username} is switched off.`);
  }

  protected async resetTwoFactor(): Promise<void> {
    const account = this.account();
    if (!account) {
      return;
    }
    const sure = await this._confirm.ask({
      title: `Turn off two-factor for ${account.username}?`,
      message: 'For somebody who lost both the phone and the recovery codes. Their recovery codes and remembered devices go with it; they can turn it on again themselves.',
      confirmLabel: 'Turn off two-factor',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this._run('2fa', this._adminApi.resetUserTwoFactor(account.id), 'Two-factor authentication is off for this account.');
  }

  /** Lets an account that waits in; the person is mailed once their address is confirmed. */
  protected approve(): void {
    const account = this.account();
    if (!account) {
      return;
    }
    this.actionError.set(null);
    this.busyAction.set('approve');
    this._adminApi.approve(account.id).subscribe({
      next: (decision) => {
        this.busyAction.set(null);
        this._toast.success(decision.mailed ? `${account.username} is approved, and was emailed.` : `${account.username} is approved.`);
        this.reload();
      },
      error: (error: ApiError) => {
        this.busyAction.set(null);
        this.actionError.set(adminErrorText(error));
      },
    });
  }

  /** Switches an account that waits off - nothing is deleted - and mails nobody. */
  protected async decline(): Promise<void> {
    const account = this.account();
    if (!account) {
      return;
    }
    const sure = await this._confirm.ask({
      title: `Decline ${account.username}?`,
      message: 'The account is switched off: nothing is deleted, and its address stays taken. Switching it on again puts it back on the Approvals page. Nobody is emailed.',
      confirmLabel: 'Decline',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this._run('decline', this._adminApi.decline(account.id), `${account.username} is declined and switched off.`);
  }

  protected async endSession(session: SessionEntry): Promise<void> {
    const sure = await this._confirm.ask({
      title: 'End this session?',
      message: 'That browser is signed out of the IAM and of every site it used.',
      confirmLabel: 'End session',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this._run(`session:${session.id}`, this._adminApi.endSession(session.id), 'The session has been ended.');
  }

  protected saveRoles(): void {
    const account = this.account();
    if (!account) {
      return;
    }
    this.rolesError.set(null);
    this.savingRoles.set(true);
    this._adminApi.setUserRoles(account.id, this.roles().map(grantFromKey)).subscribe({
      next: (detail) => {
        this.savingRoles.set(false);
        this.detail.set(detail);
        this._toast.success('Roles saved.');
      },
      error: (error: ApiError) => {
        this.savingRoles.set(false);
        this.rolesError.set(adminErrorText(error));
      },
    });
  }

  protected resetRoles(): void {
    this.roles.set(this._savedRoles());
    this.rolesError.set(null);
  }

  // ── Presentation ───────────────────────────────────────────────────────────

  protected device(session: SessionEntry): string {
    return deviceLabel(session.user_agent) ?? 'Unknown browser';
  }

  protected outcomeTone(outcome: string): BadgeTone {
    if (outcome === 'ok') {
      return 'success';
    }
    if (outcome === 'sent') {
      return 'neutral';
    }
    return outcome.includes('totp') || outcome === 'unconfirmed' ? 'warning' : 'danger';
  }

  protected tokenState(token: PersonalToken): 'active' | 'expired' | 'revoked' {
    if (token.revoked_at) {
      return 'revoked';
    }
    return new Date(token.expires_at).getTime() <= Date.now() ? 'expired' : 'active';
  }

  private _run(action: string, request: Observable<unknown>, done: string): void {
    this.actionError.set(null);
    this.busyAction.set(action);
    request.subscribe({
      next: () => {
        this.busyAction.set(null);
        this._toast.success(done);
        this.reload();
      },
      error: (error: ApiError) => {
        this.busyAction.set(null);
        this.actionError.set(adminErrorText(error));
      },
    });
  }
}
