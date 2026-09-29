import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent, IconName } from '../icon/icon.component';

/**
 * "Nothing here yet", said calmly: an icon, a heading, a line of text, and
 * whatever action makes the list not empty.
 */
@Component({
  selector: 'app-empty-state',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './empty-state.component.html',
  styleUrl: './empty-state.component.scss',
})
export class EmptyStateComponent {
  readonly icon = input<IconName>('sparkle');
  readonly heading = input.required<string>();
  readonly text = input<string | null>(null);
}
