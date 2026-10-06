import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { SessionService } from '../../core/auth/session.service';
import { NavItem, ShellComponent } from '../../layouts/shell/shell.component';
import { ButtonComponent } from '../../ui/button/button.component';
import { EmptyStateComponent } from '../../ui/empty-state/empty-state.component';

const NAV: NavItem[] = [
  { link: '/admin', label: 'Dashboard', icon: 'dashboard', exact: true },
  { link: '/admin/users', label: 'Users', icon: 'users' },
  { link: '/admin/approvals', label: 'Approvals', icon: 'clock' },
  { link: '/admin/sessions', label: 'Sessions', icon: 'laptop' },
  { link: '/admin/applications', label: 'Applications', icon: 'appWindow' },
  { link: '/admin/announcements', label: 'Announcements', icon: 'send' },
  { link: '/admin/emails', label: 'Email log', icon: 'mail' },
  { link: '/admin/audit', label: 'Audit log', icon: 'history' },
  { link: '/admin/settings', label: 'Settings', icon: 'sliders' },
  { link: '/admin/translations', label: 'Translations', icon: 'languages' },
  { link: '/admin/languages', label: 'Languages', icon: 'globe' },
  { link: '/admin/keys', label: 'Signing keys', icon: 'key' },
  { link: '/admin/migrations', label: 'Migrations', icon: 'database' },
];

/**
 * The admin panel, English only.
 *
 * Somebody signed in without the IAM's ADMIN role sees "you don't have access"
 * in place of the page, at the address they asked for - no redirect, so the
 * same link works the moment the role is granted. The pages are never created
 * for them, so nothing asks the API for what it would refuse anyway.
 */
@Component({
  selector: 'app-admin-layout',
  imports: [RouterLink, RouterOutlet, ShellComponent, ButtonComponent, EmptyStateComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './admin-layout.component.html',
  styleUrl: './admin-layout.component.scss',
})
export class AdminLayoutComponent {
  protected readonly nav = NAV;
  protected readonly isAdmin = inject(SessionService).isAdmin;
}
