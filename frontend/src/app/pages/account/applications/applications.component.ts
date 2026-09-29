import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { AccountApplication } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AppAvatarComponent } from '../../../ui/app-avatar/app-avatar.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';

/**
 * /account/applications - the anotoki sites, and what this account may do on
 * each. A members-only site nobody invited the account to is not listed at
 * all (the backend leaves it out); one it has roles in but may not use right
 * now - switched off, say - is, with "No access".
 */
@Component({
  selector: 'app-applications',
  imports: [AppDatePipe, TPipe, AppAvatarComponent, BadgeComponent, ButtonComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './applications.component.html',
  styleUrl: './applications.component.scss',
})
export class ApplicationsComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _i18n = inject(TranslationService);

  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly applications = signal<AccountApplication[]>([]);

  constructor() {
    this.load();
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
