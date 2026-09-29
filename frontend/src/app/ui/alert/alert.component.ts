import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { IconComponent, IconName } from '../icon/icon.component';

export type AlertTone = 'info' | 'success' | 'warning' | 'danger';

const ICONS: Record<AlertTone, IconName> = {
  info: 'info',
  success: 'checkCircle',
  warning: 'alert',
  danger: 'xCircle',
};

/**
 * A callout: a coloured box with an icon, an optional heading and content.
 *
 * `announce` makes it `role="alert"`, for a message that appears because of
 * something the person just did (a refused sign-in) and must be heard now.
 * Leave it off for notices that are simply part of the page, or a screen
 * reader will interrupt the reading of it to shout them. Content marked
 * `alertActions` goes in a row of buttons underneath.
 */
@Component({
  selector: 'app-alert',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[attr.role]': 'announce() ? "alert" : null', '[attr.data-tone]': 'tone()', class: 'alert' },
  templateUrl: './alert.component.html',
  styleUrl: './alert.component.scss',
})
export class AlertComponent {
  readonly tone = input<AlertTone>('info');
  readonly heading = input<string | null>(null);
  readonly announce = input(false);

  protected readonly icon = computed(() => ICONS[this.tone()]);
}
