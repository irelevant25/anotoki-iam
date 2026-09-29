import { ChangeDetectionStrategy, Component, booleanAttribute, input, model } from '@angular/core';
import { uniqueId } from '../unique-id';

/**
 * A checkbox with its label as content: `<app-checkbox [(checked)]="x">Keep me signed in</app-checkbox>`.
 *
 * A native checkbox, restyled, inside its own `<label>` - so the whole line is
 * the click target, Space toggles it, and a screen reader hears one control
 * with one name. `hint` is a quieter line under the label, tied to it with
 * `aria-describedby`.
 */
@Component({
  selector: 'app-checkbox',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './checkbox.component.html',
  styleUrl: './checkbox.component.scss',
})
export class CheckboxComponent {
  readonly checked = model(false);
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly name = input('');
  readonly hint = input<string | null>(null);

  protected readonly hintId = uniqueId('checkbox-hint');
}
