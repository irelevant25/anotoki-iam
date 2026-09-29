import { ChangeDetectionStrategy, Component, booleanAttribute, input, model } from '@angular/core';
import { uniqueId } from '../unique-id';

export interface RadioOption {
  value: string;
  label: string;
  hint?: string;
}

/**
 * One choice out of a few, each explained: a `<fieldset>` of native radios
 * drawn as cards.
 *
 * Native radios, so the arrow keys move the choice and a screen reader
 * announces "2 of 2" without any help. The whole card is the label.
 */
@Component({
  selector: 'app-radio-group',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './radio-group.component.html',
  styleUrl: './radio-group.component.scss',
})
export class RadioGroupComponent {
  readonly label = input.required<string>();
  readonly options = input.required<RadioOption[]>();
  readonly value = model<string>('');
  readonly disabled = input(false, { transform: booleanAttribute });

  protected readonly name = uniqueId('radio');
}
