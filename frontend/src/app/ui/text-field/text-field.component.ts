import { ChangeDetectionStrategy, Component, ElementRef, afterNextRender, booleanAttribute, computed, input, model, output, viewChild } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

export type TextFieldType = 'text' | 'email' | 'url' | 'search' | 'tel' | 'number' | 'date';

/**
 * A labelled text input: label, hint, error, and optional prefix/suffix.
 *
 * It renders a real `<input>` with a real `<label for>`, so password managers,
 * autofill and screen readers see an ordinary form field. The hint and the
 * error are tied to it with `aria-describedby`, and the error is a
 * `role="alert"`, so it is announced the moment it appears.
 *
 * Two-way `value` is a string whatever the type - a number field included -
 * because what a person types is text until the page decides otherwise.
 *
 * Content marked `fieldPrefix` / `fieldSuffix` sits inside the border, before
 * or after the text.
 */
@Component({
  selector: 'app-text-field',
  imports: [IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './text-field.component.html',
  styleUrl: './text-field.component.scss',
})
export class TextFieldComponent {
  readonly label = input.required<string>();
  readonly value = model<string>('');
  readonly type = input<TextFieldType>('text');
  readonly name = input<string>('');
  readonly autocomplete = input<string>('');
  readonly inputmode = input<string>('');
  readonly placeholder = input<string>('');
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly required = input(false, { transform: booleanAttribute });
  readonly optional = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly readonly = input(false, { transform: booleanAttribute });
  readonly autofocus = input(false, { transform: booleanAttribute });
  readonly maxlength = input<number | null>(null);
  readonly minlength = input<number | null>(null);
  readonly min = input<number | string | null>(null);
  readonly max = input<number | string | null>(null);
  readonly pattern = input<string>('');
  /** Off by default: addresses, usernames and codes are not prose. */
  readonly spellcheck = input<'true' | 'false'>('false');
  readonly autocapitalize = input<string>('');

  readonly blurred = output<void>();

  protected readonly inputId = uniqueId('field');
  protected readonly hintId = `${this.inputId}-hint`;
  protected readonly errorId = `${this.inputId}-error`;

  protected readonly describedBy = computed(() => (this.error() ? this.errorId : this.hint() ? this.hintId : null));

  private readonly _input = viewChild.required<ElementRef<HTMLInputElement>>('input');

  constructor() {
    afterNextRender(() => {
      if (this.autofocus()) {
        this.focus();
      }
    });
  }

  focus(): void {
    this._input().nativeElement.focus();
  }

  protected onInput(event: Event): void {
    this.value.set((event.target as HTMLInputElement).value);
  }
}
