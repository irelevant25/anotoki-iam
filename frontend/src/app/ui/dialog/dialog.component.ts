import { ChangeDetectionStrategy, Component, DestroyRef, ElementRef, afterNextRender, inject, input, output, viewChild } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { IconComponent } from '../icon/icon.component';
import { uniqueId } from '../unique-id';

// Nothing taken out of the tab order on purpose (tabindex="-1") - the hidden
// address a password manager files a password under is no place to start
// typing, nor to Tab to.
const FOCUSABLE = ['a[href]', 'button:not([disabled])', 'input:not([disabled]):not([type="hidden"])', 'select:not([disabled])', 'textarea:not([disabled])', '[tabindex]']
  .map((selector) => `${selector}:not([tabindex="-1"])`)
  .join(',');

/**
 * A modal dialog on the native `<dialog>` element.
 *
 * The page decides whether it exists - `@if (editing()) { <app-dialog …> }` -
 * and the dialog opens itself with `showModal()` when it appears, so its
 * content starts fresh every time it opens. It asks to be closed through
 * `dismiss` (Escape, the backdrop, the close button); the page then removes it.
 *
 * `showModal()` makes the rest of the page inert, which is most of a focus
 * trap already. Tab is kept cycling inside as well, so focus never wanders to
 * the browser's own controls and back into an inert page. Focus goes to the
 * first `[autofocus]` element inside, else the first focusable one, and back
 * to wherever it was when the dialog closes.
 *
 * `dismissible` false is for the one dialog that must not close by accident -
 * the recovery codes, shown once - and `busy` holds it open while a request
 * it started is in flight.
 */
@Component({
  selector: 'app-dialog',
  imports: [IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './dialog.component.html',
  styleUrl: './dialog.component.scss',
})
export class DialogComponent {
  readonly heading = input.required<string>();
  readonly description = input<string | null>(null);
  readonly size = input<'sm' | 'md' | 'lg'>('md');
  readonly dismissible = input(true);
  readonly busy = input(false);

  readonly dismiss = output<void>();

  protected readonly headingId = uniqueId('dialog-title');
  protected readonly descriptionId = uniqueId('dialog-description');
  protected pointerDownTarget: EventTarget | null = null;

  private readonly _dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly _returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;

  constructor() {
    afterNextRender(() => {
      const dialog = this._dialog().nativeElement;
      if (!dialog.open) {
        dialog.showModal();
      }
      this._focusFirst(dialog);
    });

    inject(DestroyRef).onDestroy(() => {
      const dialog = this._dialog().nativeElement;
      if (dialog.open) {
        dialog.close();
      }
      if (this._returnFocus?.isConnected) {
        this._returnFocus.focus();
      }
    });
  }

  protected requestDismiss(): void {
    if (this.dismissible() && !this.busy()) {
      this.dismiss.emit();
    }
  }

  /** The browser's own close request (Escape, a back gesture): always ours to decide. */
  protected onCancel(event: Event): void {
    event.preventDefault();
    this.requestDismiss();
  }

  protected onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      // Handled here as well as in `cancel`: Chrome closes a dialog on a second
      // Escape even when the first cancel was refused, which would lose the
      // recovery codes with no way back.
      event.preventDefault();
      event.stopPropagation();
      this.requestDismiss();
      return;
    }

    if (event.key === 'Tab') {
      this._trapTab(event);
    }
  }

  /**
   * A click on the backdrop lands on the <dialog> itself, because the panel
   * fills everything inside it. Only a click that also started there counts -
   * a text selection dragged out of a field ends on the backdrop too.
   */
  protected onClick(event: MouseEvent): void {
    const dialog = this._dialog().nativeElement;
    if (event.target === dialog && this.pointerDownTarget === dialog) {
      this.requestDismiss();
    }
  }

  private _focusFirst(dialog: HTMLDialogElement): void {
    const preferred = dialog.querySelector<HTMLElement>('[autofocus]');
    const target = preferred ?? this._focusable(dialog).find((element) => !element.classList.contains('close')) ?? this._focusable(dialog)[0];
    target?.focus();
  }

  private _trapTab(event: KeyboardEvent): void {
    const focusable = this._focusable(this._dialog().nativeElement);
    if (focusable.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || !this._dialog().nativeElement.contains(active))) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  }

  private _focusable(root: HTMLElement): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((element) => element.offsetParent !== null || element === document.activeElement);
  }
}
