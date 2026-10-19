import { ChangeDetectionStrategy, Component, computed, effect, inject, untracked } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { UpdateBannerComponent } from '@anotoki/lib/migrations';
import { ShellComponent, ShellNavItem } from '@anotoki/lib/shell';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { SessionService } from '../../core/auth/session.service';
import { Changelog } from '../../core/changelog/changelog.service';
import { IconName } from '../../core/kit/icons';
import { AppTopBarComponent } from '../../layouts/top-bar/top-bar.component';

/** The panel's sections: the frame's side navigation (a strip under the bar on a phone). */
const NAV: readonly (ShellNavItem & { icon: IconName })[] = [
  { link: '/admin', label: 'Dashboard', icon: 'dashboard', exact: true },
  { link: '/admin/users', label: 'Users', icon: 'users' },
  { link: '/admin/approvals', label: 'Approvals', icon: 'clock' },
  { link: '/admin/sessions', label: 'Sessions', icon: 'laptop' },
  { link: '/admin/applications', label: 'Applications', icon: 'appWindow' },
  { link: '/admin/announcements', label: 'Announcements', icon: 'send' },
  { link: '/admin/emails', label: 'Email log', icon: 'mail' },
  { link: '/admin/audit', label: 'Audit log', icon: 'history' },
  { link: '/admin/errors', label: 'Error log', icon: 'alert' },
  { link: '/admin/feedback', label: 'Feedback', icon: 'message' },
  { link: '/admin/settings', label: 'Settings', icon: 'sliders' },
  { link: '/admin/translations', label: 'Translations', icon: 'languages' },
  { link: '/admin/languages', label: 'Languages', icon: 'globe' },
  { link: '/admin/keys', label: 'Signing keys', icon: 'key' },
  { link: '/admin/backups', label: 'Backups', icon: 'layers' },
  { link: '/admin/migrations', label: 'Migrations', icon: 'database' },
];

/**
 * The admin panel, English only: the family's frame (@anotoki/lib/shell) with
 * its side navigation of the panel's sections, under the family's top bar.
 *
 * Somebody signed in without the IAM's ADMIN role sees "you don't have access"
 * in place of the page, at the address they asked for - no redirect, so the
 * same link works the moment the role is granted. The pages are never created
 * for them, so nothing asks the API for what it would refuse anyway.
 *
 * An account whose deletion is pending is no administrator meanwhile
 * (`is_admin` false): it is told so, and where the deletion is cancelled.
 *
 * Above every page, for the administrators: the anotoki family's one line
 * "A database update is waiting" with a link to Migrations, while one waits
 * (`<anotoki-update-banner>`, which asks the server once). The IAM goes on
 * working meanwhile - it is the door every site signs in through - so nobody
 * else is told anything.
 *
 * Under every page, as in every anotoki admin panel (the owner, 2026-10-18):
 * the family's footer (@anotoki/lib/footer), in English like the panel - the
 * IAM's version, What's new and Feedback (the IAM never closes while an update
 * waits, so its form always takes a message), then Terms · Privacy · Contact.
 * What people sent through that form is the Feedback page's (the family's,
 * `@anotoki/lib/admin/feedback`), which says what is new; the side navigation
 * counts it beside Feedback (the library's FeedbackUnread, anotoki-lib 0.8.1:
 * asked once as an administrator opens the panel, kept up by that page).
 */
@Component({
  selector: 'app-admin-layout',
  imports: [RouterLink, RouterOutlet, UpdateBannerComponent, ShellComponent, AppTopBarComponent, ButtonComponent, EmptyStateComponent, SiteFooterComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './admin-layout.component.html',
  styleUrl: './admin-layout.component.scss',
})
export class AdminLayoutComponent {
  private readonly _session = inject(SessionService);

  private readonly _unread = inject(FeedbackUnread);

  /**
   * The panel's sections, the count of new feedback beside Feedback (none at 0, or before the server said) - for an
   * administrator alone: the count lives as long as the tab, and an administrator's would otherwise stay beside
   * Feedback for whoever signs in after them without a reload.
   */
  protected readonly nav = computed(() => {
    const unread = this._session.isAdmin() ? this._unread.count() : null;
    return NAV.map((item) => (item.link === '/admin/feedback' ? { ...item, badge: unread } : item));
  });
  /** The footer's version and What's new: the list the start asked for (app.config.ts). */
  protected readonly changelog = inject(Changelog);
  protected readonly isAdmin = this._session.isAdmin;
  protected readonly deletionPending = computed(() => !!this._session.user()?.deletion_due_at);

  constructor() {
    // Asked only for an administrator: anybody else's ask would be refused anyway.
    effect(() => {
      if (this.isAdmin()) {
        untracked(() => void this._unread.refresh());
      }
    });
  }
}
