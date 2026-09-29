import { ChangeDetectionStrategy, Component, ElementRef, booleanAttribute, computed, inject, input } from '@angular/core';
import { SpinnerComponent } from '../spinner/spinner.component';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

/**
 * The look of a button, put on a real `<button>` or `<a>`.
 *
 * An attribute component rather than a wrapper, so the element keeps
 * everything the browser gives a button - type, form submission, keyboard,
 * focus - and a link stays a link a person can open in a new tab.
 *
 * `loading` keeps the label in place (the spinner sits beside it, so the
 * button does not change width under the pointer), disables the button, and
 * says `aria-busy`. `disabled` is an input here, not the native property, so it
 * is written back to the element as an attribute.
 */
@Component({
  selector: 'button[appButton], a[appButton]',
  imports: [SpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'btn',
    '[class]': 'classes()',
    '[attr.disabled]': 'isButton && (disabled() || loading()) ? "" : null',
    '[attr.aria-disabled]': '!isButton && (disabled() || loading()) ? "true" : null',
    '[attr.aria-busy]': 'loading() ? "true" : null',
    '[attr.type]': 'isButton ? type() : null',
  },
  templateUrl: './button.component.html',
  styleUrl: './button.component.scss',
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>('secondary');
  readonly size = input<ButtonSize>('md');
  readonly loading = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly block = input(false, { transform: booleanAttribute });
  /** A square button holding one icon; it needs an `aria-label`. */
  readonly iconOnly = input(false, { transform: booleanAttribute });
  /** `button` by default, so a button inside a form never submits it by accident. */
  readonly type = input<'button' | 'submit' | 'reset'>('button');

  protected readonly isButton = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement.tagName === 'BUTTON';

  protected readonly classes = computed(() =>
    [
      'btn',
      `btn-${this.variant()}`,
      `btn-${this.size()}`,
      this.block() ? 'btn-block' : '',
      this.iconOnly() ? 'btn-icon' : '',
      this.loading() ? 'btn-loading' : '',
      !this.isButton && (this.disabled() || this.loading()) ? 'btn-disabled' : '',
    ]
      .filter(Boolean)
      .join(' '),
  );
}
