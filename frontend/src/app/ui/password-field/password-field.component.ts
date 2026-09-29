import { ChangeDetectionStrategy, Component, ElementRef, afterNextRender, booleanAttribute, computed, input, model, signal, viewChild } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

/**
 * A password input with a show/hide toggle.
 *
 * `autocomplete` is required rather than defaulted: `current-password` and
 * `new-password` are what tell a password manager whether to fill a saved
 * password or offer to generate one, and a form that gets it wrong trains
 * people to stop trusting their manager.
 *
 * The toggle is a real button with `aria-pressed`, and it stays in the tab
 * order: some people need to check what they typed. Each field starts hidden.
 */
@Component({
  selector: 'app-password-field',
  imports: [IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './password-field.component.html',
  styleUrl: './password-field.component.scss',
})
export class PasswordFieldComponent {
  readonly label = input.required<string>();
  readonly value = model<string>('');
  readonly autocomplete = input.required<'current-password' | 'new-password' | 'off'>();
  readonly name = input<string>('');
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly required = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly autofocus = input(false, { transform: booleanAttribute });
  readonly maxlength = input<number | null>(null);

  protected readonly visible = signal(false);

  protected readonly inputId = uniqueId('password');
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
