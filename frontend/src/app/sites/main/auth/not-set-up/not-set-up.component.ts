import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { SiteStatus, SiteStatusComponent } from '@anotoki/lib/migrations';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { sameOriginPath } from '../../../../shared/auth/next-path';
import { TPipe } from '../../../../shared/i18n/t.pipe';

/**
 * /not-set-up - where every page lands while the backend answers
 * 503 `not_set_up`: it has no configuration, so nothing here can work.
 *
 * What it says is the anotoki family's status page (`<anotoki-site-status>`,
 * @anotoki/lib), in the IAM's own words where it has them: while the IAM was
 * never installed, that it is not set up yet and the way to its installer -
 * /setup.php, PHP rather than a route of this app, so a plain link, which the
 * dev server proxies to the API like /api - and, for a developer, how to start
 * the API that serves it. Once a real host's install has run, never the
 * installer again (it has deleted itself): the IAM is "not available right
 * now", and the page asks again by itself.
 *
 * It asks the server itself whatever sent it here, so a reload says the truth
 * too; an IAM that answers - ready, or going on while an update waits - sends
 * it back to the page it came from, from scratch, so the whole app asks again.
 *
 * A page of its own, with no header and no footer (the owner's rule of
 * 2026-10-18, the whole family's): on a route outside every frame
 * (main.routes.ts) - no bar, no language or theme switch, no night sky, no
 * footer - in a `<main>` of its own, as the family's gate draws its status
 * page on the sites that have one. The page it goes back to brings its frame.
 */
@Component({
  selector: 'app-not-set-up',
  imports: [SiteStatusComponent, TPipe, SpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-set-up.component.html',
  styleUrl: './not-set-up.component.scss',
})
export class NotSetUpComponent {
  private readonly _status = inject(SiteStatus);

  /** The page that found the IAM not set up. */
  readonly next = input<string | undefined>();

  /** The IAM cannot be used: the family's page says why. Anything else is a moment's wait. */
  protected readonly blocked = computed(() => this._status.state() === 'not-set-up' || this._status.state() === 'unavailable');
  /** Only while the installer is offered: on this computer, or a host not installed yet. */
  protected readonly neverInstalled = computed(() => this._status.state() === 'not-set-up' && !this._status.installed());

  constructor() {
    void this._status.check().then((state) => {
      if (state === 'ready' || state === 'update-pending') {
        this.goBack();
      }
    });
  }

  /**
   * Back to `next` - by itself, the moment the IAM answers, so the address is
   * one a crafted link may have written: only a path that stays on this site
   * (sameOriginPath()), and never this page again, which would be a loop.
   */
  private goBack(): void {
    const next = sameOriginPath(this.next());
    const path = next.split(/[?#]/)[0];
    location.assign(path === '/not-set-up' || path.startsWith('/not-set-up/') ? '/' : next);
  }
}
