import { ChangeDetectionStrategy, Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { LanguageSwitcherComponent, ThemeToggleComponent } from '@anotoki/lib/shell';
import { IconComponent } from '@anotoki/lib/ui';
import { TPipe } from '../../core/i18n/t.pipe';

/**
 * The frame of every page that works without a session: signing in and out,
 * registering, the mailed links, errors.
 *
 * On a wide screen, a night sky on the left says what this is - one account
 * for every anotoki site - and the form sits on the right. On a phone the sky
 * shrinks to a band with the logo, and the card follows under it.
 *
 * The sky is always night, in both themes: it is the brand, not a surface.
 * Its stars are CSS gradients and its twinkle stops under reduced motion.
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet, TPipe, IconComponent, LanguageSwitcherComponent, ThemeToggleComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './auth-layout.component.html',
  styleUrl: './auth-layout.component.scss',
})
export class AuthLayoutComponent {
  protected readonly year = new Date().getFullYear();
}
