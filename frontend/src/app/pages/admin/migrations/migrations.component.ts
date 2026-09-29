import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { MigrationsApplyResult, MigrationsStatus } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { ConfirmService } from '../../../ui/confirm/confirm.service';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { adminErrorText } from '../admin-errors';

/**
 * /admin/migrations - the schema, for a host with no shell.
 *
 * On a machine with one, `php migration.php` does the same. Here the pending
 * files run in order, each in its own transaction, and the run stops at the
 * first that fails - every migration assumes the ones before it ran.
 */
@Component({
  selector: 'app-migrations',
  imports: [AppDatePipe, AlertComponent, BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent, SpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './migrations.component.html',
  styleUrl: './migrations.component.scss',
})
export class MigrationsComponent {
  private readonly _adminApi = inject(AdminApi);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly status = signal<MigrationsStatus | null>(null);
  protected readonly result = signal<MigrationsApplyResult | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly applying = signal(false);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this._adminApi.migrations().subscribe({
      next: (status) => this.status.set(status),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected async apply(): Promise<void> {
    const pending = this.status()?.pending.length ?? 0;
    const sure = await this._confirm.ask({
      title: pending === 1 ? 'Apply 1 pending migration?' : `Apply ${pending} pending migrations?`,
      message: 'They run now, against the live database, in order - stopping at the first one that fails. Make sure there is a fresh backup.',
      confirmLabel: 'Apply',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.applying.set(true);
    this.result.set(null);
    this._adminApi.applyMigrations().subscribe({
      next: (result) => {
        this.applying.set(false);
        this.result.set(result);
        if (!result.failed && result.applied.length) {
          this._toast.success('Migrations applied.');
        }
        this.load();
      },
      error: (error: ApiError) => {
        this.applying.set(false);
        this._toast.error(adminErrorText(error));
      },
    });
  }
}
