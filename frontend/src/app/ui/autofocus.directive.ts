import { Directive, ElementRef, afterNextRender, booleanAttribute, inject, input } from '@angular/core';

/**
 * Focuses its element once it has been drawn: `<h1 tabindex="-1" appAutofocus>`.
 *
 * The sign-in pages swap one step for another in place. Without this, focus
 * would be left on a button that no longer exists and a screen reader would
 * say nothing about the new step; with it on the step's first field - or on
 * its heading, where there is no field - the change is where the reader is.
 */
@Directive({ selector: '[appAutofocus]' })
export class AutofocusDirective {
  readonly appAutofocus = input(true, { transform: booleanAttribute });

  constructor() {
    const element = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
    afterNextRender(() => {
      if (this.appAutofocus()) {
        element.focus({ preventScroll: false });
      }
    });
  }
}
