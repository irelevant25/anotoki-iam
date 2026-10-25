import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { LanguageSwitcherComponent, ThemeToggleComponent } from '@anotoki/lib/shell';
import { AnnouncementComponent } from '@anotoki/lib/site-settings';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { Changelog } from '../../../../shared/changelog/changelog.service';
import { TPipe } from '../../../../shared/i18n/t.pipe';

/**
 * The frame of every page that works without a session: signing in and out,
 * registering, the mailed links, errors. (Not the not-set-up page: the
 * family's status page is a page of its own, on no frame - main.routes.ts.)
 *
 * On a wide screen, a night sky on the left says what this is - one account
 * for every anotoki site - and the form sits on the right. On a phone the sky
 * shrinks to a band with the logo, and the card follows under it. Under the
 * form, on every width, the anotoki family's footer (@anotoki/lib/footer):
 * its top border, the row - the IAM's version and What's new (the newest
 * entry of public/changelog.json and the IAM's own dialog, shared/changelog;
 * neither while there is no list) and Feedback (the family's form, anybody's
 * here: shared/feedback) - and Terms · Privacy · Contact.
 *
 * The sky is always night, in both themes: it is the brand, not a surface.
 * Its stars are CSS gradients and its twinkle stops under reduced motion.
 *
 * Above it all, across the page, the administrators' announcement when there
 * is one - the family's bar (`<anotoki-announcement>`, the site settings' one
 * part the IAM offers: shared/site-settings), as above every frame but the
 * admin panel's.
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet, TPipe, IconComponent, LanguageSwitcherComponent, ThemeToggleComponent, SiteFooterComponent, AnnouncementComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './auth-layout.component.html',
  styleUrl: './auth-layout.component.scss',
})
export class AuthLayoutComponent {
  protected readonly year = new Date().getFullYear();
  /** The footer's version and What's new: the list the start asked for (app.config.ts). */
  protected readonly changelog = inject(Changelog);
}
