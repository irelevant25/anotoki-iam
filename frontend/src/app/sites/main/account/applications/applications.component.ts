import { ChangeDetectionStrategy, Component, computed, effect, inject, signal, untracked } from '@angular/core';
import { AvatarComponent } from '@anotoki/lib/ui/avatar';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { AnotokiAgoPipe } from '@anotoki/lib/ui/dates';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { AccountApi } from '../../../../api/account.api';
import { ApiError } from '../../../../api/api-error';
import { AccountApplication } from '../../../../api/models';
import { SessionService } from '../../../../shared/auth/session.service';
import { errorText } from '../../../../shared/i18n/error-text';
import { TPipe } from '../../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../../shared/i18n/translation.service';

/**
 * /account/applications - the anotoki sites, and what this account may do on
 * each. A members-only site nobody invited the account to is not listed at
 * all (the backend leaves it out); one it has roles in but may not use right
 * now - switched off, say - is, with "No access".
 *
 * While the account's deletion is pending, every site says no access for that
 * reason, not for want of an invitation - and the page says so.
 */
@Component({
  selector: 'app-applications',
  imports: [AnotokiAgoPipe, TPipe, AvatarComponent, BadgeComponent, ButtonComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './applications.component.html',
  styleUrl: './applications.component.scss',
})
export class ApplicationsComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _session = inject(SessionService);

  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly applications = signal<AccountApplication[]>([]);
  protected readonly deletionPending = computed(() => !!this._session.user()?.deletion_due_at);

  constructor() {
    // Loaded again when the deletion is asked for or cancelled - the notice
    // above every account page cancels it - since every site's answer goes
    // with it.
    effect(() => {
      this.deletionPending();
      untracked(() => this.load());
    });
  }

  protected load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this._accountApi.applications().subscribe({
      next: (applications) => {
        this.applications.set(applications);
        this.loading.set(false);
      },
      error: (error: ApiError) => {
        this.loadError.set(errorText(error, (key) => this._i18n.t(key)));
        this.loading.set(false);
      },
    });
  }

  protected host(url: string): string {
    try {
      return new URL(url).host;
    } catch {
      return url;
    }
  }
}
