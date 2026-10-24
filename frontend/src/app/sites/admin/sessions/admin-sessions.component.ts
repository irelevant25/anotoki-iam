import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { SelectComponent, SelectOption, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { catchError, debounceTime, distinctUntilChanged, of, skip, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AdminSession, AdminSessionQuery, AdminSessionsResponse } from '../../../api/models';
import { AppDatePipe } from '../../../shared/i18n/app-date.pipe';
import { TPipe } from '../../../shared/i18n/t.pipe';
import { deviceLabel } from '../../../shared/util/user-agent';
import { sessionMethodKey, sessionStateKey } from '../../../shared/i18n/session-labels';
import { adminErrorText } from '../shared/admin-errors';

/** The backend answers at most this many rows, newest first. */
const ROW_LIMIT = 200;

/**
 * /admin/sessions - every sign-in to the IAM, across all accounts.
 *
 * Filters live in the address like the users list; `?user_id=` narrows it to
 * one account (the user detail page has its own list, this is for looking
 * across people - one address, one method).
 */
@Component({
  selector: 'app-admin-sessions',
  imports: [
    RouterLink,
    AppDatePipe,
    TPipe,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './admin-sessions.component.html',
  styleUrl: './admin-sessions.component.scss',
})
export class AdminSessionsComponent {
  readonly search = input<string>();
  readonly method = input<string>();
  readonly status = input<string>();
  /** Snake case because it is the query parameter's own name, which the router binds by. */
  readonly user_id = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly response = signal<AdminSessionsResponse | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly endingId = signal<number | null>(null);
  protected readonly searchText = signal('');
  protected readonly rowLimit = ROW_LIMIT;

  protected readonly statusOptions: SelectOption[] = [
    { value: 'active', label: 'Signed in' },
    { value: 'ended', label: 'Ended or expired' },
  ];
  protected readonly methodOptions = computed<SelectOption[]>(() => (this.response()?.methods ?? []).map((method) => ({ value: method, label: method })));

  protected readonly query = computed<AdminSessionQuery>(() => ({
    search: this.search() || undefined,
    method: this.method() || undefined,
    status: (this.status() as AdminSessionQuery['status']) || undefined,
    user_id: this.user_id() ? Number(this.user_id()) : undefined,
  }));
  protected readonly filtered = computed(() => Object.values(this.query()).some((value) => value !== undefined));

  protected readonly methodKey = sessionMethodKey;
  protected readonly stateKey = sessionStateKey;

  constructor() {
    const destroyRef = inject(DestroyRef);

    toObservable(this.query)
      .pipe(
        tap((query) => {
          this.loading.set(true);
          this.loadError.set(null);
          this.searchText.set(query.search ?? '');
        }),
        switchMap((query) =>
          this._adminApi.sessions(query).pipe(
            catchError((error: ApiError) => {
              this.loadError.set(adminErrorText(error));
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(destroyRef),
      )
      .subscribe((response) => {
        if (response) {
          this.response.set(response);
        }
        this.loading.set(false);
      });

    toObservable(this.searchText)
      .pipe(skip(1), debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(destroyRef))
      .subscribe((value) => {
        if (value.trim() !== (this.search() ?? '')) {
          this.setFilter('search', value.trim());
        }
      });
  }

  protected setFilter(name: keyof AdminSessionQuery, value: string): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { [name]: value || null }, queryParamsHandling: 'merge', replaceUrl: true });
  }

  protected clearFilters(): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: {}, replaceUrl: true });
  }

  protected reload(): void {
    this._adminApi.sessions(this.query()).subscribe({
      next: (response) => {
        this.response.set(response);
        this.loadError.set(null);
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected device(session: AdminSession): string {
    return deviceLabel(session.user_agent) ?? 'Unknown browser';
  }

  protected async end(session: AdminSession): Promise<void> {
    const sure = await this._confirm.ask({
      title: `End ${session.username}'s session?`,
      message: 'That browser is signed out of the IAM, and every refresh token issued under the session stops working.',
      confirmLabel: 'End session',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.endingId.set(session.id);
    this._adminApi.endSession(session.id).subscribe({
      next: () => {
        this.endingId.set(null);
        this._toast.success('The session has been ended.');
        this.reload();
      },
      error: (error: ApiError) => {
        this.endingId.set(null);
        this._toast.error(adminErrorText(error));
      },
    });
  }
}
