import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { AlertComponent } from '../alert/alert.component';
import { ButtonComponent } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';

/**
 * A load that failed, in place of what should have been there, with a retry.
 *
 * Inline rather than a toast: the gap in the page is where the reader is
 * looking, and a retry button there is the obvious next thing to do.
 */
@Component({
  selector: 'app-error-state',
  imports: [AlertComponent, ButtonComponent, IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './error-state.component.html',
  styleUrl: './error-state.component.scss',
})
export class ErrorStateComponent {
  readonly heading = input<string | null>(null);
  readonly message = input.required<string>();
  readonly retryLabel = input<string | null>(null);
  readonly retry = output<void>();
}
