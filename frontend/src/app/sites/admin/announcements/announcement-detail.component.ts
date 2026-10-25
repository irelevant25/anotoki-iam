import { ChangeDetectionStrategy, Component, DestroyRef, OnInit, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HoldsUnsavedChanges } from '@anotoki/lib/translations';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { AnotokiAgoPipe, AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { SelectComponent, SelectOption, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { PaginationComponent } from '@anotoki/lib/ui/pagination';
import { ProgressBarComponent } from '@anotoki/lib/ui/progress';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { ToastService } from '@anotoki/lib/ui/toast';
import { catchError, debounceTime, distinctUntilChanged, map, of, skip, switchMap } from 'rxjs';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AnnouncementDetail, AnnouncementRecipient, Paged } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';
import { RECIPIENT_LABELS, STATUS_LABELS, people, templateLabel } from './announcement-labels';

/**
 * /admin/announcements/:id - one announcement: its words in each language,
 * who it was for, and how every mail went.
 *
 * While it is sending, this page is what sends it: batch after batch, each a
 * request of its own (the host has no cron, and a request has half a minute),
 * for as long as the page is open and not paused. Arriving from the composer
 * (`?send=1`) it starts at once; opened later, "Continue sending" picks up
 * where it was left. Two pages sending one announcement never send a mail
 * twice - the server hands each batch rows no other one holds. Stop ends it:
 * what is not sent then never goes.
 */
@Component({
  selector: 'app-announcement-detail',
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
    IconComponent,
    PageHeaderComponent,
    PaginationComponent,
    ProgressBarComponent,
    SelectComponent,
    SpinnerComponent,
    TabsComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '(window:beforeunload)': 'onBeforeUnload($event)' },
  templateUrl: './announcement-detail.component.html',
  styleUrl: './announcement-detail.component.scss',
})
export class AnnouncementDetailComponent implements OnInit, HoldsUnsavedChanges {
  /** The route's `:id`. */
  readonly id = input.required<string>();
  /** `?send=1`: the composer just made it - start sending. */
  readonly send = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _route = inject(ActivatedRoute);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly detail = signal<AnnouncementDetail | null>(null);
  protected readonly loadError = signal<string | null>(null);
  /** This page is asking for batch after batch. */
  protected readonly running = signal(false);
  protected readonly runError = signal<string | null>(null);
  /** When the host's hourly allowance has room again (ISO), while the page waits for it. */
  protected readonly waitingUntil = signal<string | null>(null);
  /** The last batch could take nothing: another page holds what is left, and this one waits its turn. */
  protected readonly othersSending = signal(false);
  private _timer: ReturnType<typeof setTimeout> | null = null;
  protected readonly stopping = signal(false);
  protected readonly language = signal('en');

  protected readonly recipients = signal<Paged<AnnouncementRecipient> | null>(null);
  protected readonly recipientsError = signal<string | null>(null);
  protected readonly recipientsLoading = signal(false);
  protected readonly recipientStatus = signal('');
  protected readonly recipientSearch = signal('');
  private readonly _searchApplied = signal('');
  protected readonly recipientPage = signal(1);

  protected readonly statusLabels = STATUS_LABELS;
  protected readonly recipientLabels = RECIPIENT_LABELS;
  protected readonly templateLabel = templateLabel;
  protected readonly people = people;

  protected readonly statusOptions: SelectOption[] = [
    { value: 'sent', label: 'Sent' },
    { value: 'pending', label: 'Not sent yet' },
    { value: 'failed', label: 'Failed' },
    { value: 'skipped', label: 'Skipped' },
  ];

  protected readonly tabs = computed<TabItem[]>(() => (this.detail()?.languages ?? []).map((code) => ({ id: code, label: code.toUpperCase() })));
  protected readonly version = computed(() => this.detail()?.content[this.language()] ?? null);

  /** How much of it is done: sent, failed or skipped, of all. */
  protected readonly done = computed(() => {
    const counts = this.detail()?.counts;
    return counts ? counts.total - counts.pending : 0;
  });
  protected readonly percent = computed(() => {
    const total = this.detail()?.counts.total ?? 0;
    return total > 0 ? Math.round((this.done() / total) * 100) : 0;
  });

  constructor() {
    const destroyRef = inject(DestroyRef);
    destroyRef.onDestroy(() => this.pause());

    toObservable(this.recipientSearch)
      .pipe(
        skip(1),
        map((term) => term.trim()),
        distinctUntilChanged(),
        debounceTime(300),
        takeUntilDestroyed(),
      )
      .subscribe((term) => {
        this._searchApplied.set(term);
        this.recipientPage.set(1);
      });

    toObservable(computed(() => ({ status: this.recipientStatus(), search: this._searchApplied(), page: this.recipientPage() })))
      .pipe(
        skip(1),
        switchMap((query) => this._recipients(query)),
        takeUntilDestroyed(),
      )
      .subscribe();
  }

  ngOnInit(): void {
    const startSending = this.send() === '1';
    if (startSending) {
      void this._router.navigate([], { relativeTo: this._route, queryParams: { send: null }, queryParamsHandling: 'merge', replaceUrl: true });
    }
    this._load(startSending);
  }

  protected reload(): void {
    this._load(false);
  }

  // ── Sending ────────────────────────────────────────────────────────────────

  protected run(): void {
    if (this.running()) {
      return;
    }
    this.runError.set(null);
    this.running.set(true);
    this._next();
  }

  /** This page stops asking; the announcement stays sending, to be continued. */
  protected pause(): void {
    this.running.set(false);
    this.waitingUntil.set(null);
    this.othersSending.set(false);
    if (this._timer !== null) {
      clearTimeout(this._timer);
      this._timer = null;
    }
  }

  protected async stop(): Promise<void> {
    const detail = this.detail();
    if (!detail) {
      return;
    }
    const sure = await this._confirm.ask({
      title: 'Stop sending?',
      message: `${people(detail.counts.pending)} have not been sent it yet, and never will be. What went cannot be taken back.`,
      confirmLabel: 'Stop sending',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.pause();
    this.stopping.set(true);
    this._adminApi.stopAnnouncement(detail.id).subscribe({
      next: (stopped) => {
        this.stopping.set(false);
        this.detail.set(stopped);
        this._toast.success('Stopped.');
        this._refreshRecipients();
      },
      error: (error: ApiError) => {
        this.stopping.set(false);
        this._toast.error(adminErrorText(error));
        this.reload();
      },
    });
  }

  private _next(): void {
    const detail = this.detail();
    if (!this.running() || !detail) {
      return;
    }

    const pendingBefore = detail.counts.pending;
    this._adminApi.sendAnnouncementBatch(detail.id).subscribe({
      next: (after) => {
        this.detail.set(after);
        this.othersSending.set(false);
        if (after.status === 'sending' && after.wait) {
          // The host's hourly allowance is used up: on by itself once it has room.
          this.waitingUntil.set(new Date(Date.now() + after.wait * 1000).toISOString());
          this._timer = setTimeout(() => {
            this._timer = null;
            this.waitingUntil.set(null);
            this._next();
          }, after.wait * 1000);
          return;
        }
        if (after.status === 'sending' && after.counts.pending === pendingBefore) {
          // Nothing it could take: another page holds what is left. Asked
          // again in a moment, not at once and again and again.
          this.othersSending.set(true);
          this._timer = setTimeout(() => {
            this._timer = null;
            this._next();
          }, 3000);
          return;
        }
        if (after.status === 'sending') {
          this._next();
          return;
        }
        this.running.set(false);
        if (after.status === 'sent') {
          this._toast.success(`Sent: ${after.counts.sent} of ${people(after.counts.total)}.`);
        }
        this._refreshRecipients();
      },
      error: (error: ApiError) => {
        this.running.set(false);
        this.runError.set(adminErrorText(error));
        this.reload();
      },
    });
  }

  // ── Leaving ────────────────────────────────────────────────────────────────

  canLeave(): boolean | Promise<boolean> {
    if (!this.running()) {
      return true;
    }
    return this._confirm
      .ask({
        title: 'Leave while it is sending?',
        message: 'Sending pauses until this page is open again and "Continue sending" is pressed.',
        confirmLabel: 'Leave',
        tone: 'danger',
      })
      .then((leave) => {
        if (leave) {
          this.pause();
        }
        return leave;
      });
  }

  protected onBeforeUnload(event: BeforeUnloadEvent): void {
    if (this.running()) {
      event.preventDefault();
    }
  }

  // ── Loading ────────────────────────────────────────────────────────────────

  private _load(thenSend: boolean): void {
    this.loadError.set(null);
    this._adminApi.announcement(Number(this.id())).subscribe({
      next: (detail) => {
        this.detail.set(detail);
        if (!detail.languages.includes(this.language())) {
          this.language.set(detail.languages[0] ?? 'en');
        }
        if (thenSend && detail.status === 'sending') {
          this.run();
        }
        this._refreshRecipients();
      },
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  private _refreshRecipients(): void {
    this._recipients({ status: this.recipientStatus(), search: this._searchApplied(), page: this.recipientPage() }).subscribe();
  }

  private _recipients(query: { status: string; search: string; page: number }) {
    this.recipientsLoading.set(true);
    return this._adminApi.announcementRecipients(Number(this.id()), { status: query.status || undefined, search: query.search || undefined, page: query.page }).pipe(
      map((recipients) => {
        this.recipients.set(recipients);
        this.recipientsError.set(null);
        this.recipientsLoading.set(false);
      }),
      catchError((error: ApiError) => {
        this.recipientsError.set(adminErrorText(error));
        this.recipientsLoading.set(false);
        return of(undefined);
      }),
    );
  }
}
