import { ChangeDetectionStrategy, Component, booleanAttribute, computed, input, model } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

/** A labelled multi-line field, styled and wired like TextField. */
@Component({
  selector: 'app-textarea',
  imports: [IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './textarea.component.html',
  styleUrl: './textarea.component.scss',
})
export class TextareaComponent {
  readonly label = input.required<string>();
  readonly value = model<string>('');
  readonly name = input<string>('');
  readonly rows = input<number>(3);
  readonly placeholder = input<string>('');
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly optional = input(false, { transform: booleanAttribute });
  readonly maxlength = input<number | null>(null);

  protected readonly inputId = uniqueId('textarea');
  protected readonly hintId = `${this.inputId}-hint`;
  protected readonly errorId = `${this.inputId}-error`;
  protected readonly describedBy = computed(() => (this.error() ? this.errorId : this.hint() ? this.hintId : null));
}
