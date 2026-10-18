import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { SiteFooterComponent } from '@anotoki/lib/footer';
import { LanguageSwitcherComponent, ThemeToggleComponent } from '@anotoki/lib/shell';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TPipe } from '../../core/i18n/t.pipe';

/**
 * The frame of every page that works without a session: signing in and out,
 * registering, the mailed links, errors. (Not the not-set-up page: the
 * family's status page is a page of its own, on no frame - app.routes.ts.)
 *
 * On a wide screen, a night sky on the left says what this is - one account
 * for every anotoki site - and the form sits on the right. On a phone the sky
 * shrinks to a band with the logo, and the card follows under it. Under the
 * form, on every width, the anotoki family's footer (@anotoki/lib/footer):
 * its top border and Terms · Privacy · Contact - no row above them, the IAM
 * having no version to show, no What's new and no feedback form.
 *
 * The sky is always night, in both themes: it is the brand, not a surface.
 * Its stars are CSS gradients and its twinkle stops under reduced motion.
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet, TPipe, IconComponent, LanguageSwitcherComponent, ThemeToggleComponent, SiteFooterComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './auth-layout.component.html',
  styleUrl: './auth-layout.component.scss',
})
export class AuthLayoutComponent {
  protected readonly year = new Date().getFullYear();
}
