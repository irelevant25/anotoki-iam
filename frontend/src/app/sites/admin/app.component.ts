import { ChangeDetectionStrategy, Component, computed, effect, inject, untracked } from '@angular/core';
import { RouterLink, RouterOutlet } from '@angular/router';
import { FeedbackUnread } from '@anotoki/lib/admin';
import { AdminFrameAction, AdminFrameBrand, AdminFrameComponent, AdminFrameLink, AdminFramePerson, SideNavGroup, SideNavLink } from '@anotoki/lib/admin/frame';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { UpdateBannerComponent } from '@anotoki/lib/migrations';
import { ThemeToggleComponent } from '@anotoki/lib/shell';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { SessionService } from '../../shared/auth/session.service';
import { SignOutService } from '../../shared/auth/sign-out';
import { Changelog } from '../../shared/changelog/changelog.service';
import { TranslationService } from '../../shared/i18n/translation.service';
import { IconName } from '../../shared/kit/icons';

/** A page of the panel in the frame's column, its icon one the IAM's templates name. */
type PanelLink = SideNavLink & { path: string; icon: IconName };

/**
 * The panel's seventeen pages, as the frame's column lists them - the one
 * shape of every anotoki admin panel (the owner's request of 2026-10-25): the
 * IAM's own pages at the top with no heading - the dashboard, who may get in
 * and where (the accounts, the ones waiting for approval, the sign-ins, the
 * sites), the mails -, then Localization, then System: the IAM's own system
 * pages first (its settings and signing keys), then the family's, in the
 * family's order and words - Site settings, Feedback, Error log, Audit log,
 * Backups, Migrations (the family's People page is none of the IAM's: its
 * Users page is the accounts themselves).
 */
const GROUPS: readonly { id: string; label?: string; links: readonly PanelLink[] }[] = [
  {
    id: 'site',
    links: [
      { label: 'Dashboard', path: '/admin', exact: true, icon: 'dashboard' },
      { label: 'Users', path: '/admin/users', icon: 'users' },
      { label: 'Approvals', path: '/admin/approvals', icon: 'clock' },
      { label: 'Sessions', path: '/admin/sessions', icon: 'laptop' },
      { label: 'Applications', path: '/admin/applications', icon: 'appWindow' },
      { label: 'Announcements', path: '/admin/announcements', icon: 'send' },
      { label: 'Email log', path: '/admin/emails', icon: 'mail' },
    ],
  },
  {
    id: 'localization',
    label: 'Localization',
    links: [
      { label: 'Translations', path: '/admin/translations', icon: 'languages' },
      { label: 'Languages', path: '/admin/languages', icon: 'globe' },
    ],
  },
  {
    id: 'system',
    label: 'System',
    links: [
      { label: 'Settings', path: '/admin/settings', icon: 'sliders' },
      { label: 'Signing keys', path: '/admin/keys', icon: 'key' },
      { label: 'Site settings', path: '/admin/site-settings', icon: 'wrench' },
      { label: 'Feedback', path: '/admin/feedback', icon: 'message' },
      { label: 'Error log', path: '/admin/errors', icon: 'alert' },
      { label: 'Audit log', path: '/admin/audit', icon: 'history' },
      { label: 'Backups', path: '/admin/backups', icon: 'layers' },
      { label: 'Migrations', path: '/admin/migrations', icon: 'database' },
    ],
  },
];

/**
 * The admin panel, English only, in the anotoki family's admin frame
 * (`<anotoki-admin-frame>`, @anotoki/lib/admin/frame, anotoki-lib 0.10.0 - the
 * owner's request of 2026-10-24: every admin panel the genshin site's, so it is
 * never mistaken for the site): no top bar, a column on the left - the
 * panel's name, who is signed in, "Back to your account", Sign out (the
 * account pages' bar's own, SignOutService), the theme (the family's switch:
 * the account's theme, as on every page) and the panel's pages in groups -
 * and the page on the right. Up to 860 px a row with the name and Menu, the
 * column in the side navigation's drawer.
 *
 * Somebody signed in without the IAM's ADMIN role sees "you don't have access"
 * in place of the panel, at the address they asked for - no redirect, so the
 * same link works the moment the role is granted; no frame round it, so
 * nothing suggests there is more to see. The pages are never created for
 * them, so nothing asks the API for what it would refuse anyway.
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
 * Under every page, as in every anotoki admin panel (the owner, 2026-10-18) -
 * the closed ones too: the family's footer (@anotoki/lib/footer), in English
 * like the panel - the IAM's version, What's new and Feedback (the IAM never
 * closes while an update waits, so its form always takes a message), then
 * Terms · Privacy · Contact. What people sent through that form is the
 * Feedback page's (the family's, `@anotoki/lib/admin/feedback`), which says
 * what is new; the column counts it beside Feedback (the library's
 * FeedbackUnread, anotoki-lib 0.8.1: asked once as an administrator opens the
 * panel, kept up by that page).
 *
 * The frame's own words - Skip to content, Menu, Sign out, the theme's -
 * are the kit's, which the IAM says with its own keys (shared/kit/kit-words.ts):
 * the translations answer in English in the admin panel, whatever the reader
 * chose on the account pages.
 */
@Component({
  selector: 'app-admin-root',
  imports: [RouterLink, RouterOutlet, AdminFrameComponent, ThemeToggleComponent, UpdateBannerComponent, ButtonComponent, EmptyStateComponent, SiteFooterComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './app.component.html',
  styleUrl: './app.component.scss',
})
export class AppComponent {
  private readonly _session = inject(SessionService);
  private readonly _signOut = inject(SignOutService);
  private readonly _i18n = inject(TranslationService);
  private readonly _unread = inject(FeedbackUnread);

  /**
   * The panel's name as it has been since 2026-10-07, the family's form for an
   * admin area: "anotoki account · admin panel" - the site's word the account
   * pages' own (`brand.area`, read in English here), so a rewording on the
   * Translations page renames both. Its link is the dashboard. Too long for
   * the column's one line, it breaks after the dot: "admin panel" is held
   * together (a no-break space).
   */
  protected readonly brand = computed<AdminFrameBrand>(() => ({ name: `anotoki ${this._i18n.t('brand.area')} · admin panel`, path: '/admin' }));

  protected readonly person = computed<AdminFramePerson | null>(() => {
    const me = this._session.user();
    return me ? { name: me.username, email: me.email } : null;
  });

  protected readonly back: AdminFrameLink = { label: 'Back to your account', path: '/account' };

  /** Sign out, the account pages' bar's own: the column waits on it until the server has answered. */
  protected readonly signOut: AdminFrameAction = () => this._signOut.signOut();

  /**
   * The panel's pages, the count of new feedback beside Feedback (none at 0, or before the server said) - for an
   * administrator alone: the count lives as long as the tab, and an administrator's would otherwise stay beside
   * Feedback for whoever signs in after them without a reload.
   */
  protected readonly groups = computed<SideNavGroup[]>(() => {
    const unread = this._session.isAdmin() ? this._unread.count() : null;
    return GROUPS.map((group) => ({ ...group, links: group.links.map((link) => (link.path === '/admin/feedback' ? { ...link, badge: unread } : link)) }));
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
