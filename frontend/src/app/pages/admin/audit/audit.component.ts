import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { catchError, debounceTime, distinctUntilChanged, merge, of, skip, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AuditEntry, AuditQuery, Paged } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { BadgeComponent } from '../../../ui/badge/badge.component';
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

/** The kinds of thing the backend writes audit rows about. */
const TARGET_TYPES: SelectOption[] = ['user', 'application', 'session', 'token', 'key', 'settings'].map((type) => ({ value: type, label: type }));

/** How long a one-line summary of the details may run before it is cut. */
const SUMMARY_LENGTH = 90;

/**
 * /admin/audit - every change made from the admin console, and the
 * security-relevant ones accounts make to themselves.
 *
 * `details` is whatever JSON the backend stored with the row. It is shown on
 * one line, cut short, and in full when the row is opened - enough to answer
 * "who gave this person ADMIN, and what did they have before".
 */
@Component({
  selector: 'app-audit',
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
  templateUrl: './audit.component.html',
  styleUrl: './audit.component.scss',
})
export class AuditComponent {
  readonly actor = input<string>();
  readonly action = input<string>();
  /** Snake case because it is the query parameter's own name, which the router binds by. */
  readonly target_type = input<string>();
  readonly page = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);

  protected readonly targetTypes = TARGET_TYPES;
  protected readonly result = signal<Paged<AuditEntry> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly actorText = signal('');
  protected readonly actionText = signal('');

  protected readonly query = computed<AuditQuery>(() => ({
    actor: this.actor() || undefined,
    action: this.action() || undefined,
    target_type: this.target_type() || undefined,
    page: this.page() ? Number(this.page()) : undefined,
  }));
  protected readonly filtered = computed(() => !!(this.actor() || this.action() || this.target_type()));

  constructor() {
    const destroyRef = inject(DestroyRef);

    toObservable(this.query)
      .pipe(
        tap((query) => {
          this.loading.set(true);
          this.loadError.set(null);
          this.actorText.set(query.actor ?? '');
          this.actionText.set(query.action ?? '');
        }),
        switchMap((query) =>
          this._adminApi.audit(query).pipe(
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

    // Both text filters wait for a pause in typing before they become requests.
    merge(
      toObservable(this.actorText).pipe(
        skip(1),
        debounceTime(300),
        distinctUntilChanged(),
        tap((value) => value.trim() !== (this.actor() ?? '') && this.setFilter('actor', value.trim())),
      ),
      toObservable(this.actionText).pipe(
        skip(1),
        debounceTime(300),
        distinctUntilChanged(),
        tap((value) => value.trim() !== (this.action() ?? '') && this.setFilter('action', value.trim())),
      ),
    )
      .pipe(takeUntilDestroyed(destroyRef))
      .subscribe();
  }

  protected setFilter(name: Exclude<keyof AuditQuery, 'page'>, value: string): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { [name]: value || null, page: null }, queryParamsHandling: 'merge', replaceUrl: true });
  }

  protected setPage(page: number): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { page: page > 1 ? page : null }, queryParamsHandling: 'merge' });
  }

  protected clearFilters(): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: {}, replaceUrl: true });
  }

  protected reload(): void {
    this._adminApi.audit(this.query()).subscribe({
      next: (result) => {
        this.result.set(result);
        this.loadError.set(null);
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected hasDetails(entry: AuditEntry): boolean {
    const details = entry.details;
    if (details === null || details === undefined || details === '') {
      return false;
    }
    return typeof details !== 'object' || Object.keys(details as object).length > 0;
  }

  /** The details on one line, cut short: `{"before":[…],"after":[…]}`. */
  protected summary(entry: AuditEntry): string {
    const text = typeof entry.details === 'string' ? entry.details : JSON.stringify(entry.details);
    return text.length > SUMMARY_LENGTH ? `${text.slice(0, SUMMARY_LENGTH - 1)}…` : text;
  }

  protected pretty(entry: AuditEntry): string {
    if (typeof entry.details === 'string') {
      try {
        return JSON.stringify(JSON.parse(entry.details), null, 2);
      } catch {
        return entry.details;
      }
    }
    return JSON.stringify(entry.details, null, 2);
  }
}
