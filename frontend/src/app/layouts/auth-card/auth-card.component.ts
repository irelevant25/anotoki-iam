import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RequestApplication } from '../../core/api/models';
import { AppAvatarComponent } from '../../ui/app-avatar/app-avatar.component';

/**
 * The card every sign-in page is drawn in.
 *
 * With an `application` - somebody on their way to a site - the site's logo
 * and name head the card, because "where am I signing in to?" is the first
 * thing a careful person checks. Everything else is the page's own: its
 * heading and steps go in the body (styled by pages/auth/_auth-page.scss),
 * `authFooter` is a last line inside the card, and `authBelow` sits under it -
 * the way back to the site, mostly.
 *
 * The heading is not a slot of its own because the pages swap whole steps
 * with @switch, and a block with several nodes projects as one piece.
 */
@Component({
  selector: 'app-auth-card',
  imports: [AppAvatarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './auth-card.component.html',
  styleUrl: './auth-card.component.scss',
})
export class AuthCardComponent {
  readonly application = input<RequestApplication | null | undefined>(null);
}
