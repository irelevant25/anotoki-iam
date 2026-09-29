import { ChangeDetectionStrategy, Component, booleanAttribute, computed, input, model } from '@angular/core';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

/**
 * A labelled native `<select>`.
 *
 * Native on purpose: on a phone it opens the platform's own picker, which is
 * better than anything drawn in the page, and it is accessible without a line
 * of ARIA. Values are strings; a page that wants a number converts it.
 */
@Component({
  selector: 'app-select',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './select.component.html',
  styleUrl: './select.component.scss',
})
export class SelectComponent {
  readonly label = input.required<string>();
  readonly value = model<string>('');
  readonly options = input.required<SelectOption[]>();
  /** A first, empty option ("Any", "Choose…"); null for none. */
  readonly placeholder = input<string | null>(null);
  readonly name = input<string>('');
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly disabled = input(false, { transform: booleanAttribute });
  /** Keeps the label for screen readers only - for filter bars where the placeholder says it. */
  readonly hideLabel = input(false, { transform: booleanAttribute });
  /** The compact height used in filter bars. */
  readonly inline = input(false, { transform: booleanAttribute });

  protected readonly inputId = uniqueId('select');
  protected readonly hintId = `${this.inputId}-hint`;
  protected readonly errorId = `${this.inputId}-error`;
  protected readonly describedBy = computed(() => (this.error() ? this.errorId : this.hint() ? this.hintId : null));
}
