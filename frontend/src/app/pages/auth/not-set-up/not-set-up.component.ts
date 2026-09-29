import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TPipe } from '../../../core/i18n/t.pipe';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { IconComponent } from '../../../ui/icon/icon.component';

/**
 * /not-set-up - where every page lands while the backend answers
 * 503 `not_set_up`: it has no configuration yet, so nothing here can work.
 *
 * The way out is the installer, /setup.php, which is PHP rather than a route
 * of this app - hence a plain link (a full navigation), which the dev server
 * proxies to the API like /api. "Check again" reloads the page somebody was
 * on, from scratch, so the whole app asks the server again.
 */
@Component({
  selector: 'app-not-set-up',
  imports: [TPipe, AuthCardComponent, AutofocusDirective, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './not-set-up.component.html',
  styleUrl: './not-set-up.component.scss',
})
export class NotSetUpComponent {
  /** The page that found the IAM not set up. */
  readonly next = input<string | undefined>();

  protected checkAgain(): void {
    const next = this.next();
    const local = !!next && next.startsWith('/') && !next.startsWith('//') && !next.includes('\\') && !next.startsWith('/not-set-up');
    location.assign(local ? next : '/');
  }
}
