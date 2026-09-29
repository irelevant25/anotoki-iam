import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, of, skip, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { EmailFilters, EmailLogEntry, EmailQuery, Paged } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { BadgeComponent, BadgeTone } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { PaginationComponent } from '../../../ui/pagination/pagination.component';
import { SelectComponent, SelectOption } from '../../../ui/select/select.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { adminErrorText } from '../admin-errors';

/**
 * /admin/emails - what was mailed, and whether it went.
 *
 * Metadata only: the bodies carry live secrets and are never kept, and the
 * subject only for the kinds whose subject holds nothing secret.
 */
@Component({
  selector: 'app-emails',
  imports: [
    RouterLink,
    AppDatePipe,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    PaginationComponent,
    SelectComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './emails.component.html',
  styleUrl: './emails.component.scss',
})
export class EmailsComponent {
  readonly kind = input<string>();
  readonly status = input<string>();
  readonly recipient = input<string>();
  readonly from = input<string>();
  readonly to = input<string>();
  readonly page = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);

  protected readonly result = signal<Paged<EmailLogEntry> | null>(null);
  protected readonly filters = signal<EmailFilters | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly recipientText = signal('');

  protected readonly kindOptions = computed<SelectOption[]>(() => (this.filters()?.kinds ?? []).map((kind) => ({ value: kind, label: kind })));
  protected readonly statusOptions = computed<SelectOption[]>(() => (this.filters()?.statuses ?? []).map((status) => ({ value: status, label: status })));

  protected readonly query = computed<EmailQuery>(() => ({
    kind: this.kind() || undefined,
    status: this.status() || undefined,
    recipient: this.recipient() || undefined,
    from: this.from() || undefined,
    to: this.to() || undefined,
    page: this.page() ? Number(this.page()) : undefined,
  }));
  protected readonly filtered = computed(() => {
    const { page: _page, ...filters } = this.query();
    return Object.values(filters).some((value) => value !== undefined);
  });

  constructor() {
    const destroyRef = inject(DestroyRef);

    this._adminApi.emailFilters().subscribe({ next: (filters) => this.filters.set(filters), error: () => undefined });

    toObservable(this.query)
      .pipe(
        tap((query) => {
          this.loading.set(true);
          this.loadError.set(null);
          this.recipientText.set(query.recipient ?? '');
        }),
        switchMap((query) =>
          this._adminApi.emails(query).pipe(
            catchError((error: ApiError) => {
              this.loadError.set(adminErrorText(error));
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(destroyRef),
      )
      .subscribe((result) => {
        if (result) {
          this.result.set(result);
        }
        this.loading.set(false);
      });

    toObservable(this.recipientText)
      .pipe(skip(1), debounceTime(300), distinctUntilChanged(), takeUntilDestroyed(destroyRef))
      .subscribe((value) => {
        if (value.trim() !== (this.recipient() ?? '')) {
          this.setFilter('recipient', value.trim());
        }
      });
  }

  /** A filter changed: back to the first page, which is where its results start. */
  protected setFilter(name: Exclude<keyof EmailQuery, 'page'>, value: string): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { [name]: value || null, page: null }, queryParamsHandling: 'merge', replaceUrl: true });
  }

  protected setPage(page: number): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { page: page > 1 ? page : null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: {}, replaceUrl: true });
  }

  protected reload(): void {
    this._adminApi.emails(this.query()).subscribe({
      next: (result) => {
        this.result.set(result);
        this.loadError.set(null);
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected statusTone(status: string): BadgeTone {
    if (status === 'sent') {
      return 'success';
    }
    if (status === 'failed' || status === 'error') {
      return 'danger';
    }
    return 'neutral';
  }
}
