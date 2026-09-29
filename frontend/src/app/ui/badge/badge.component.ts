import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type BadgeTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';

/**
 * A small label - "Confirmed", "2FA", a role name.
 *
 * The colour repeats what the word says and never replaces it: a badge must
 * read the same to somebody who cannot tell green from red.
 */
@Component({
  selector: 'app-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'badge', '[attr.data-tone]': 'tone()', '[class.badge-mono]': 'mono()' },
  templateUrl: './badge.component.html',
  styleUrl: './badge.component.scss',
})
export class BadgeComponent {
  readonly tone = input<BadgeTone>('neutral');
  readonly dot = input(false);
  readonly mono = input(false);
}
