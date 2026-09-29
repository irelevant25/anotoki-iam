import { ChangeDetectionStrategy, Component, booleanAttribute, input, model } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';
import { uniqueId } from '../unique-id';

/**
 * An on/off switch: `<app-switch [(checked)]="on">Accept password sign-in</app-switch>`.
 *
 * A native checkbox with `role="switch"`, so it is announced as "on"/"off"
 * rather than "checked", and keyboard and form behaviour come for free.
 *
 * For a switch that saves the moment it is flipped, the page binds `checked`
 * two ways, saves on `checkedChange`, and puts the old value back if the save
 * fails - the switch then follows, because its state is the page's state.
 * `busy` shows the save in progress and blocks a second flip meanwhile.
 */
@Component({
  selector: 'app-switch',
  imports: [SpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './switch.component.html',
  styleUrl: './switch.component.scss',
})
export class SwitchComponent {
  readonly checked = model(false);
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly busy = input(false, { transform: booleanAttribute });
  readonly name = input('');
  readonly hint = input<string | null>(null);

  protected readonly hintId = uniqueId('switch-hint');
}
