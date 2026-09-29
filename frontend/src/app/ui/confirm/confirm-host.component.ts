import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { ButtonComponent } from '../button/button.component';
import { DialogComponent } from '../dialog/dialog.component';
import { ConfirmService } from './confirm.service';

/**
 * Draws the question ConfirmService is asking, if any.
 *
 * Focus starts on Cancel: for the destructive questions this mostly asks,
 * the safe answer should be the one a stray Enter gives.
 */
@Component({
  selector: 'app-confirm-host',
  imports: [ButtonComponent, DialogComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './confirm-host.component.html',
  styleUrl: './confirm-host.component.scss',
})
export class ConfirmHostComponent {
  protected readonly confirm = inject(ConfirmService);
}
