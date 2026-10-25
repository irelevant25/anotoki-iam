import { ChangeDetectionStrategy, Component, ElementRef, Injector, afterNextRender, computed, inject, input, signal, viewChild } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { formatDate } from '@anotoki/lib/ui/dates';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AccountApi } from '../../../api/account.api';
import { ApiError } from '../../../api/api-error';
import { SessionService } from '../../../shared/auth/session.service';
import { errorText } from '../../../shared/i18n/error-text';
import { TPipe } from '../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../shared/i18n/translation.service';

/**
 * The notice at the top of every account page while the account's deletion
 * is pending: the day it goes, that it signs in to no site meanwhile, and
 * Cancel (`DELETE /api/account/deletion`).
 *
 * In the account layout rather than on a page: whichever page the person
 * opens, the account is closed for every site, and this is where they keep
 * it. A landmark of its own, named by its heading.
 */
@Component({
  selector: 'app-deletion-banner',
  imports: [TPipe, AlertComponent, ButtonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { role: 'region', tabindex: '-1', '[attr.aria-label]': 'title()' },
  templateUrl: './deletion-banner.component.html',
  styleUrl: './deletion-banner.component.scss',
})
export class DeletionBannerComponent {
  /** When the account is deleted (`deletion_due_at`). */
  readonly due = input.required<string>();
  /**
   * Take the focus once drawn: the notice came because of what the person
   * just did on this page, and the button they did it with is gone.
   */
  readonly takeFocus = input(false);

  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);
  private readonly _host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private readonly _injector = inject(Injector);
  private readonly _button = viewChild('cancelButton', { read: ElementRef<HTMLButtonElement> });

  /** The heading - and the landmark's name - with the day as the account writes dates. */
  protected readonly title = computed(() =>
    this._i18n.t('deletion.pendingTitle', { date: formatDate(this.due(), { locale: this._i18n.locale(), choices: this._session.user()?.preferences ?? null }) }),
  );
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
    afterNextRender(() => {
      if (this.takeFocus()) {
        this._host.focus();
      }
    });
  }

  protected cancel(): void {
    this.busy.set(true);
    this.error.set(null);
    this._session.setFrom(this._accountApi.cancelDeletion()).subscribe({
      next: () => this._kept(),
      error: (error: ApiError) => {
        if (error.code === 'deletion_not_requested') {
          // Cancelled already - in another tab: the account as it is now.
          this._session.refresh().subscribe({ next: () => this._kept(), error: () => this._failed(error) });
          return;
        }
        this._failed(error);
      },
    });
  }

  /** The account, as the answer brought it, is the session's by now (setFrom(), refresh()): the notice goes with it. */
  private _kept(): void {
    this.busy.set(false);
    // The notice and its button go with the change: the focus waits at the
    // top of the page they were on, rather than falling out of it.
    this._host.closest('main')?.focus();
    this._toast.show(this._i18n.t('deletion.cancelled'), 'success', 9000);
  }

  private _failed(error: ApiError): void {
    this.busy.set(false);
    this.error.set(errorText(error, (key) => this._i18n.t(key)));
    // The button was switched off while the request was out, and the focus went with it.
    afterNextRender(() => this._button()?.nativeElement.focus(), { injector: this._injector });
  }
}
