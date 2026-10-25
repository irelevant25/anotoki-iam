import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { AnotokiAgoPipe, AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { SelectComponent, SelectOption, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { catchError, debounceTime, distinctUntilChanged, of, skip, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AdminUser, AdminUserFilters, AdminUserQuery, RoleGrant } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';
import { CreateUserDialogComponent } from './create-user-dialog.component';

const YES_NO: SelectOption[] = [
  { value: 'yes', label: 'Yes' },
  { value: 'no', label: 'No' },
];

/**
 * /admin/users - every account, newest first, filtered.
 *
 * The filters live in the address (`?status=disabled&twoFactor=no`), so a
 * filtered list can be bookmarked, shared, and come back to with the back
 * button - and the dashboard's tiles link straight into one.
 */
@Component({
  selector: 'app-users-list',
  imports: [
    RouterLink,
    AnotokiAgoPipe,
    AnotokiDatePipe,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    TextFieldComponent,
    CreateUserDialogComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './users-list.component.html',
  styleUrl: './users-list.component.scss',
})
export class UsersListComponent {
  // The filters, from the query string.
  readonly search = input<string>();
  readonly status = input<string>();
  readonly confirmed = input<string>();
  readonly twoFactor = input<string>();
  readonly google = input<string>();
  readonly mustChange = input<string>();
  readonly language = input<string>();
  readonly application = input<string>();
  readonly role = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);
  private readonly _toast = inject(ToastService);

  protected readonly users = signal<AdminUser[]>([]);
  protected readonly filters = signal<AdminUserFilters | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly creating = signal(false);
  protected readonly searchText = signal('');

  protected readonly yesNo = YES_NO;
  protected readonly statusOptions: SelectOption[] = [
    { value: 'enabled', label: 'Enabled' },
    { value: 'disabled', label: 'Switched off' },
  ];
  protected readonly languageOptions = computed<SelectOption[]>(() => (this.filters()?.languages ?? []).map((language) => ({ value: language, label: language.toUpperCase() })));
  protected readonly applicationOptions = computed<SelectOption[]>(() => (this.filters()?.applications ?? []).map((application) => ({ value: application.client_id, label: application.name })));
  protected readonly roleOptions = computed<SelectOption[]>(() => {
    const application = this.filters()?.applications.find((candidate) => candidate.client_id === this.application());
    return (application?.roles ?? []).map((role) => ({ value: role, label: role }));
  });

  /**
   * What each row's Roles cell shows: the grants beyond MEMBER (every account
   * holds MEMBER everywhere - six badges a row would say nothing), and a
   * warning per site the account is blocked from, by name. Nothing left
   * reads "Member".
   */
  protected readonly roleCells = computed(() => {
    const names = new Map((this.filters()?.applications ?? []).map((application) => [application.client_id, application.name]));
    return new Map(
      this.users().map((user) => [
        user.id,
        {
          grants: user.roles.filter((grant) => grant.role !== 'MEMBER'),
          blocked: (user.blocked_in ?? []).map((clientId) => names.get(clientId) ?? clientId),
        },
      ]),
    );
  });

  protected readonly query = computed<AdminUserQuery>(() => ({
    search: this.search() || undefined,
    status: (this.status() as AdminUserQuery['status']) || undefined,
    confirmed: (this.confirmed() as AdminUserQuery['confirmed']) || undefined,
    twoFactor: (this.twoFactor() as AdminUserQuery['twoFactor']) || undefined,
    google: (this.google() as AdminUserQuery['google']) || undefined,
    mustChange: (this.mustChange() as AdminUserQuery['mustChange']) || undefined,
    language: this.language() || undefined,
    application: this.application() || undefined,
    role: this.application() ? this.role() || undefined : undefined,
  }));

  protected readonly filtered = computed(() => Object.values(this.query()).some((value) => value !== undefined));

  constructor() {
    const destroyRef = inject(DestroyRef);

    this._adminApi.userFilters().subscribe({ next: (filters) => this.filters.set(filters), error: () => undefined });

    // Every change of the query string is one request; a newer one cancels
    // an older one still in flight, so the list never shows a stale answer.
    toObservable(this.query)
      .pipe(
        tap((query) => {
          this.loading.set(true);
          this.loadError.set(null);
          this.searchText.set(query.search ?? '');
        }),
        switchMap((query) =>
          this._adminApi.users(query).pipe(
            catchError((error: ApiError) => {
              this.loadError.set(adminErrorText(error));
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(destroyRef),
      )
      .subscribe((users) => {
        if (users) {
          this.users.set(users);
        }
        this.loading.set(false);
      });

    // Typing in the search box waits for a pause before it becomes a request.
    toObservable(this.searchText)
      .pipe(skip(1), debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(destroyRef))
      .subscribe((value) => {
        if (value.trim() !== (this.search() ?? '')) {
          this.setFilter('search', value.trim());
        }
      });
  }

  protected setFilter(name: keyof AdminUserQuery, value: string): void {
    const queryParams: Record<string, string | null> = { [name]: value || null };
    if (name === 'application') {
      queryParams['role'] = null;
    }
    void this._router.navigate([], { relativeTo: this._route, queryParams, queryParamsHandling: 'merge', replaceUrl: true });
  }

  protected clearFilters(): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: {}, replaceUrl: true });
  }

  protected reload(): void {
    this._adminApi.users(this.query()).subscribe({
      next: (users) => {
        this.users.set(users);
        this.loadError.set(null);
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected open(user: AdminUser): void {
    void this._router.navigate(['/admin/users', user.id]);
  }

  protected created(user: AdminUser | null): void {
    this.creating.set(false);
    if (user) {
      this._toast.success(`${user.username} was created.`);
      void this._router.navigate(['/admin/users', user.id]);
    }
  }

  protected isIamAdmin(user: AdminUser): boolean {
    return user.roles.some((grant) => grant.client_id === 'iam' && grant.role === 'ADMIN');
  }

  protected grantLabel(grant: RoleGrant): string {
    return `${grant.client_id}: ${grant.role}`;
  }
}
