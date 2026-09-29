import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent, IconName } from '../icon/icon.component';
import { ToastService, ToastTone } from './toast.service';

const ICONS: Record<ToastTone, IconName> = { success: 'checkCircle', info: 'info', warning: 'alert', danger: 'xCircle' };

/**
 * The toasts, bottom right (bottom centre on a phone).
 *
 * The region is always in the page, empty or not: a live region that is
 * created together with its first message is one many screen readers never
 * announce.
 */
@Component({
  selector: 'app-toast-host',
  imports: [IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './toast-host.component.html',
  styleUrl: './toast-host.component.scss',
})
export class ToastHostComponent {
  protected readonly toasts = inject(ToastService);
  protected readonly icons = ICONS;
}
