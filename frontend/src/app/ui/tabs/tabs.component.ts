import { ChangeDetectionStrategy, Component, ElementRef, input, model, viewChildren } from '@angular/core';

export interface TabItem {
  id: string;
  label: string;
  /** A number after the label - how many members, say. */
  count?: number | null;
}

/**
 * A row of tabs following the WAI-ARIA tabs pattern.
 *
 * Only the selected tab is in the tab order; the arrow keys, Home and End move
 * between tabs and select as they go. The panels are the page's own: each is
 * `role="tabpanel"` with `id="{idPrefix}-panel-{tab.id}"` and
 * `aria-labelledby="{idPrefix}-tab-{tab.id}"`, which is what these buttons
 * point at.
 */
@Component({
  selector: 'app-tabs',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './tabs.component.html',
  styleUrl: './tabs.component.scss',
})
export class TabsComponent {
  readonly tabs = input.required<TabItem[]>();
  readonly active = model.required<string>();
  readonly label = input.required<string>();
  readonly idPrefix = input('tabs');

  private readonly _buttons = viewChildren<ElementRef<HTMLButtonElement>>('tabButton');

  protected onKeydown(event: KeyboardEvent): void {
    const tabs = this.tabs();
    const index = tabs.findIndex((tab) => tab.id === this.active());
    let next = index;

    switch (event.key) {
      case 'ArrowRight':
        next = (index + 1) % tabs.length;
        break;
      case 'ArrowLeft':
        next = (index - 1 + tabs.length) % tabs.length;
        break;
      case 'Home':
        next = 0;
        break;
      case 'End':
        next = tabs.length - 1;
        break;
      default:
        return;
    }

    event.preventDefault();
    this.active.set(tabs[next].id);
    this._buttons()[next]?.nativeElement.focus();
  }
}
