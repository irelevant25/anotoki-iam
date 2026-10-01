import { ChangeDetectionStrategy, Component, booleanAttribute, input, output } from '@angular/core';

/**
 * One box of the Translations page: a key's string in one language.
 *
 * A textarea of one row that grows with what is in it - without a line of
 * script: the box and a hidden copy of its text share one grid cell, and the
 * copy, which wraps as the text does, gives the cell its height.
 *
 * It holds nothing: the page says what is in it, and hears every keystroke.
 */
@Component({
  selector: 'app-translation-cell',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './translation-cell.component.html',
  styleUrl: './translation-cell.component.scss',
})
export class TranslationCellComponent {
  readonly value = input.required<string>();
  /** What a screen reader calls the box: the language and the key. */
  readonly label = input.required<string>();
  /** The language of the text, for the spelling checker and the screen reader's voice. */
  readonly language = input.required<string>();
  readonly placeholder = input('');
  /** Not as the IAM has it: changed here, and not saved yet. */
  readonly changed = input(false, { transform: booleanAttribute });
  /** The server refused this string. */
  readonly invalid = input(false, { transform: booleanAttribute });

  readonly valueChange = output<string>();

  protected onInput(event: Event): void {
    this.valueChange.emit((event.target as HTMLTextAreaElement).value);
  }
}
