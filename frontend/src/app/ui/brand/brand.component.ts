import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * The anotoki mark and a wordmark beside it.
 *
 * The logo has its own white outline, so it reads on the night sky and on a
 * dark top bar alike without a second version for dark mode. `alt` is empty:
 * the wordmark next to it already says the name, and saying it twice is noise.
 */
@Component({
  selector: 'app-brand',
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './brand.component.html',
  styleUrl: './brand.component.scss',
})
export class BrandComponent {
  readonly size = input(34);
  readonly wordmark = input(true);
  /** "anotoki" in the wordmark; without it, the area alone beside the mark (the top bar on a phone). */
  readonly showName = input(true);
  /** The part of anotoki, after the name: "account", "admin panel". */
  readonly area = input<string | null>(null);
  readonly link = input<string | null>(null);
  readonly linkLabel = input<string | null>(null);
}
