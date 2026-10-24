import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { LinkCardComponent } from '@anotoki/lib/ui/link-card';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AdminStats } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';
import { IconName } from '../../../shared/kit/icons';

interface Tile {
  key: keyof AdminStats;
  label: string;
  icon: IconName;
  link: string;
  query?: Record<string, string>;
  tone?: 'warning' | 'danger';
}

const TILES: Tile[] = [
  { key: 'awaiting_approval', label: 'Awaiting approval', icon: 'clock', link: '/admin/approvals', tone: 'warning' },
  { key: 'users', label: 'Active accounts', icon: 'users', link: '/admin/users', query: { status: 'enabled' } },
  { key: 'disabled', label: 'Switched off', icon: 'ban', link: '/admin/users', query: { status: 'disabled' } },
  { key: 'admins', label: 'IAM admins', icon: 'shield', link: '/admin/users', query: { application: 'iam', role: 'ADMIN' } },
  { key: 'active_sessions', label: 'Signed in now', icon: 'laptop', link: '/admin/sessions', query: { status: 'active' } },
  { key: 'applications', label: 'Applications', icon: 'appWindow', link: '/admin/applications' },
  { key: 'signins_24h', label: 'Sign-ins, 24 h', icon: 'checkCircle', link: '/admin/sessions' },
  { key: 'failed_24h', label: 'Failed sign-ins, 24 h', icon: 'alert', link: '/admin/audit', tone: 'warning' },
  { key: 'emails_24h', label: 'Emails sent, 24 h', icon: 'mail', link: '/admin/emails' },
];

const LINKS: { link: string; label: string; text: string; icon: IconName }[] = [
  { link: '/admin/users', label: 'Users', text: 'Accounts, roles, passwords and two-factor resets.', icon: 'users' },
  { link: '/admin/approvals', label: 'Approvals', text: 'New accounts waiting for your yes or no, when they need approval.', icon: 'clock' },
  { link: '/admin/applications', label: 'Applications', text: 'The sites that sign in here, their addresses and roles.', icon: 'appWindow' },
  { link: '/admin/settings', label: 'Settings', text: 'Switch signing in, registration, its approval and Google on or off.', icon: 'sliders' },
  { link: '/admin/keys', label: 'Signing keys', text: 'The keys tokens are signed with, and rotating them.', icon: 'key' },
];

/** /admin - the numbers at a glance, each a way into the list behind it. */
@Component({
  selector: 'app-dashboard',
  imports: [RouterLink, ErrorStateComponent, IconComponent, LinkCardComponent, PageHeaderComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './dashboard.component.html',
  styleUrl: './dashboard.component.scss',
})
export class DashboardComponent {
  private readonly _adminApi = inject(AdminApi);

  protected readonly tiles = TILES;
  protected readonly links = LINKS;
  protected readonly stats = signal<AdminStats | null>(null);
  protected readonly loadError = signal<string | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this._adminApi.stats().subscribe({
      next: (stats) => this.stats.set(stats),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }
}
