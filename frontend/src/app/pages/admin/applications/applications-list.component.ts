import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminApplication } from '../../../core/api/models';
import { AppAvatarComponent } from '../../../ui/app-avatar/app-avatar.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { adminErrorText } from '../admin-errors';

/** /admin/applications - every site that signs in here, in the order they were added; whether each requires signing in, and which roles may. */
@Component({
  selector: 'app-applications-list',
  imports: [RouterLink, AppAvatarComponent, BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './applications-list.component.html',
  styleUrl: './applications-list.component.scss',
})
export class ApplicationsListComponent {
  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);

  protected readonly applications = signal<AdminApplication[]>([]);
  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this._adminApi.applications().subscribe({
      next: (applications) => {
        this.applications.set(applications);
        this.loading.set(false);
      },
      error: (error: ApiError) => {
        this.loadError.set(adminErrorText(error));
        this.loading.set(false);
      },
    });
  }

  protected open(app: AdminApplication): void {
    void this._router.navigate(['/admin/applications', app.id]);
  }
}
