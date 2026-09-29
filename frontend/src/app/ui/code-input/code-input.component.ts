import { ChangeDetectionStrategy, Component, ElementRef, afterNextRender, booleanAttribute, computed, input, model, output, signal, viewChild } from '@angular/core';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

/**
 * A six-digit code, drawn as six boxes and typed into one real input.
 *
 * One input rather than six, because one input is what makes the rest work:
 * pasting "123 456" from an email, the phone offering the code from a text
 * message (`autocomplete="one-time-code"`), a password manager filling it,
 * and a screen reader hearing one field with one label. The input lies over
 * the boxes, transparent; the boxes only draw what it holds.
 *
 * Anything but digits is dropped as it arrives, so a paste with spaces or a
 * dash still lands whole. `complete` fires when the last digit is in, for
 * pages that submit on their own at that point.
 */
@Component({
  selector: 'app-code-input',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './code-input.component.html',
  styleUrl: './code-input.component.scss',
  host: { '[style.--cells]': 'length()' },
})
export class CodeInputComponent {
  readonly label = input.required<string>();
  readonly value = model<string>('');
  readonly length = input(6);
  readonly name = input('');
  readonly hint = input<string | null>(null);
  readonly error = input<string | null>(null);
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly autofocus = input(false, { transform: booleanAttribute });

  /** The last digit is in. Enter needs nothing extra: the input sits in the page's form. */
  readonly complete = output<string>();

  protected readonly focused = signal(false);
  protected readonly inputId = uniqueId('code');
  protected readonly hintId = `${this.inputId}-hint`;
  protected readonly errorId = `${this.inputId}-error`;
  protected readonly describedBy = computed(() => (this.error() ? this.errorId : this.hint() ? this.hintId : null));

  protected readonly cells = computed(() => Array.from({ length: this.length() }, (_, index) => this.value()[index] ?? ''));
  protected readonly activeIndex = computed(() => Math.min(this.value().length, this.length() - 1));

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

  protected onFocus(): void {
    this.focused.set(true);
    // The caret always sits after the last digit: the boxes have no way to
    // show one in the middle, so editing happens from the end.
    const input = this._input().nativeElement;
    const end = input.value.length;
    queueMicrotask(() => input.setSelectionRange(end, end));
  }

  protected onInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    const digits = input.value.replace(/\D/g, '').slice(0, this.length());

    if (input.value !== digits) {
      input.value = digits;
    }

    const wasComplete = this.value().length === this.length();
    this.value.set(digits);

    if (digits.length === this.length() && !wasComplete) {
      this.complete.emit(digits);
    }
  }
}
