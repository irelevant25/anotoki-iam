import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * A card: a surface with an optional heading row.
 *
 * Content marked `cardActions` sits at the right of the heading (it wraps
 * under it on a phone); everything else is the body. `flush` drops the body
 * padding for a table that should run edge to edge.
 */
@Component({
  selector: 'app-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'card', '[class.card-flush]': 'flush()', '[class.card-tone]': 'tone() !== null', '[attr.data-tone]': 'tone()' },
  templateUrl: './card.component.html',
  styleUrl: './card.component.scss',
})
export class CardComponent {
  readonly heading = input<string | null>(null);
  readonly subheading = input<string | null>(null);
  readonly headingId = input<string | null>(null);
  readonly flush = input(false);
  readonly tone = input<'danger' | null>(null);
}
