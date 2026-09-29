import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

const SIZES = { sm: 16, md: 24, lg: 36 } as const;

/**
 * A spinner, optionally with something to say.
 *
 * With a `label` it is a `role="status"` region, so "Loading…" is announced
 * when it appears. Without one it is decoration - inside a busy button, where
 * the button's own `aria-busy` already says it.
 *
 * Under reduced motion it keeps turning, slowly: a loading indicator that does
 * not move reads as a page that has frozen.
 */
@Component({
  selector: 'app-spinner',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './spinner.component.html',
  styleUrl: './spinner.component.scss',
})
export class SpinnerComponent {
  readonly size = input<keyof typeof SIZES>('md');
  readonly label = input<string | null>(null);
  readonly showLabel = input(false);
  readonly inline = input(false);

  protected readonly px = computed(() => SIZES[this.size()]);
}
