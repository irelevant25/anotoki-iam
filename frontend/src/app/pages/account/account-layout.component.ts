import { ChangeDetectionStrategy, Component, afterNextRender, computed, inject, signal } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SessionService } from '../../core/auth/session.service';
import { ShellComponent } from '../../layouts/shell/shell.component';
import { DeletionBannerComponent } from './deletion-banner.component';

/**
 * The account area: the shell, whose top bar has the account pages as its
 * links - and, while the account's deletion is pending, the notice above
 * every one of them that says when, and cancels it.
 */
@Component({
  selector: 'app-account-layout',
  imports: [RouterOutlet, ShellComponent, DeletionBannerComponent],
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
