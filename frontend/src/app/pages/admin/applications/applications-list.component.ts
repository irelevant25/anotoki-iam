import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AvatarComponent } from '@anotoki/lib/ui/avatar';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminApplication } from '../../../core/api/models';
import { adminErrorText } from '../admin-errors';

/** /admin/applications - every site that signs in here, in the order they were added; whether each requires signing in, and which roles may. */
@Component({
  selector: 'app-applications-list',
  imports: [RouterLink, AvatarComponent, BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent],
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
