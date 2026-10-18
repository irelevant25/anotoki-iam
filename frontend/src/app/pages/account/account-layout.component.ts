import { ChangeDetectionStrategy, Component, afterNextRender, computed, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { ShellComponent } from '@anotoki/lib/shell';
import { SessionService } from '../../core/auth/session.service';
import { AppTopBarComponent } from '../../layouts/top-bar/top-bar.component';
import { DeletionBannerComponent } from './deletion-banner.component';

/**
 * The account area: the family's frame (@anotoki/lib/shell - the skip link,
 * the bar, `<main>`), whose top bar has the account pages as its links - and,
 * while the account's deletion is pending, the notice above every one of
 * them that says when, and cancels it. Under them the family's footer
 * (@anotoki/lib/footer): Terms · Privacy · Contact, no row - the IAM has no
 * version, no What's new and no feedback form.
 */
@Component({
  selector: 'app-account-layout',
  imports: [RouterOutlet, ShellComponent, AppTopBarComponent, DeletionBannerComponent, SiteFooterComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './account-layout.component.html',
  styleUrl: './account-layout.component.scss',
})
export class AccountLayoutComponent {
  private readonly _session = inject(SessionService);

  protected readonly deletionDue = computed(() => this._session.user()?.deletion_due_at ?? null);
  /**
   * The area is on screen. A notice that appears after that appears because
   * of something done here - the deletion just asked for - and takes the
   * focus; one there from the start leaves it where the page puts it.
   */
  protected readonly drawn = signal(false);

  constructor() {
    afterNextRender(() => this.drawn.set(true));
  }
}
