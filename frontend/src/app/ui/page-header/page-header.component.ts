import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/**
 * The top of a page in the account and admin areas: the one `<h1>`, a lead
 * line, and the page's main actions at the right (under it on a phone).
 * Content marked `pageEyebrow` goes above the heading - a back link, say.
 */
@Component({
  selector: 'app-page-header',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './page-header.component.html',
  styleUrl: './page-header.component.scss',
})
export class PageHeaderComponent {
  readonly heading = input.required<string>();
  readonly lead = input<string | null>(null);
}
