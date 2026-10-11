import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { toObservable, takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent } from '@anotoki/lib/ui';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { TextFieldComponent } from '@anotoki/lib/ui/forms';
import { PaginationComponent } from '@anotoki/lib/ui/pagination';
import { ToastService } from '@anotoki/lib/ui/toast';
import { catchError, of, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AnnouncementSummary, MailSender, MailSenders, Paged } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { adminErrorText } from '../admin-errors';
import { STATUS_LABELS, people, templateLabel } from './announcement-labels';

/**
 * /admin/announcements - what was mailed to the people of the sites, newest
 * first, and the addresses it can come from.
 *
 * Each announcement opens on its own page: its words, who it was for, and how
 * every mail went - and, while it is sending, the batches. The senders are the
 * installation's own (config/mail.local.php: noreply and support), which this
 * page cannot change, and the ones added here, at the domain the IAM sends
 * from - the host sends as its own mailboxes only.
 */
@Component({
  selector: 'app-announcements',
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
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './announcements.component.html',
  styleUrl: './announcements.component.scss',
})
export class AnnouncementsComponent {
  readonly page = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly result = signal<Paged<AnnouncementSummary> | null>(null);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);

  protected readonly senders = signal<MailSenders | null>(null);
  protected readonly sendersError = signal<string | null>(null);
  protected readonly newAddress = signal('');
  protected readonly newName = signal('');
  protected readonly addError = signal<string | null>(null);
  protected readonly adding = signal(false);
  protected readonly removing = signal<string | null>(null);

  protected readonly statusLabels = STATUS_LABELS;
  protected readonly templateLabel = templateLabel;
  protected readonly people = people;

  protected readonly domains = computed(() => (this.senders()?.domains ?? []).join(', '));
  protected readonly addressPlaceholder = computed(() => 'news@' + (this.senders()?.domains.at(0) ?? 'anotoki.eu'));

  constructor() {
    toObservable(computed(() => Number(this.page()) || 1))
      .pipe(
        tap(() => {
          this.loading.set(true);
          this.loadError.set(null);
        }),
        switchMap((page) =>
          this._adminApi.announcements(page).pipe(
            catchError((error: ApiError) => {
              this.loadError.set(adminErrorText(error));
              return of(null);
            }),
          ),
        ),
        takeUntilDestroyed(),
      )
      .subscribe((result) => {
        if (result) {
          this.result.set(result);
        }
        this.loading.set(false);
      });

    this.loadSenders();
  }

  protected reload(): void {
    this.loadError.set(null);
    this._adminApi.announcements(Number(this.page()) || 1).subscribe({
      next: (result) => this.result.set(result),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected setPage(page: number): void {
    void this._router.navigate([], { relativeTo: this._route, queryParams: { page: page > 1 ? page : null }, queryParamsHandling: 'merge' });
  }

  protected loadSenders(): void {
    this.sendersError.set(null);
    this._adminApi.mailSenders().subscribe({
      next: (senders) => this.senders.set(senders),
      error: (error: ApiError) => this.sendersError.set(adminErrorText(error)),
    });
  }

  protected addSender(): void {
    const address = this.newAddress().trim();
    const name = this.newName().trim();
    if (!address || !name) {
      this.addError.set('Both the address and the name are needed.');
      return;
    }

    this.addError.set(null);
    this.adding.set(true);
    this._adminApi.addMailSender(address, name).subscribe({
      next: (senders) => {
        this.adding.set(false);
        this.senders.set(senders);
        this.newAddress.set('');
        this.newName.set('');
        this._toast.success(`${address.toLowerCase()} is a sender now.`);
      },
      error: (error: ApiError) => {
        this.adding.set(false);
        this.addError.set(adminErrorText(error));
      },
    });
  }

  protected async removeSender(sender: MailSender): Promise<void> {
    const sure = await this._confirm.ask({
      title: `Remove ${sender.address}?`,
      message: 'Nothing new can come from it. What was sent from it keeps its address in the history.',
      confirmLabel: 'Remove',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.removing.set(sender.id);
    this._adminApi.removeMailSender(sender.id).subscribe({
      next: (senders) => {
        this.removing.set(null);
        this.senders.set(senders);
        this._toast.success(`${sender.address} is removed.`);
      },
      error: (error: ApiError) => {
        this.removing.set(null);
        this._toast.error(adminErrorText(error));
        this.loadSenders();
      },
    });
  }
}
