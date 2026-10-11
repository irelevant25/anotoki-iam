import { ChangeDetectionStrategy, Component, computed, input, model } from '@angular/core';
import { ButtonComponent, IconComponent, uniqueId } from '@anotoki/lib/ui';
import { validOrigin, validRedirectUri } from './url-rules';

/**
 * A list of addresses edited as rows: redirect URIs, logout URIs, origins.
 *
 * Each row is checked as it is typed and marked on its own, because these are
 * matched byte for byte - `https://site.eu/callback` and `…/callback/` are two
 * different addresses, and only one of them will work.
 */
@Component({
  selector: 'app-url-list-editor',
  imports: [ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './url-list-editor.component.html',
  styleUrl: './url-list-editor.component.scss',
})
export class UrlListEditorComponent {
  readonly label = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly placeholder = input('https://');
  readonly kind = input<'uri' | 'origin'>('uri');
  readonly items = model<string[]>([]);

  protected readonly hintId = uniqueId('url-list-hint');
  protected readonly errorId = uniqueId('url-list-error');

  /** Blank rows are not errors - they are dropped on save. */
  protected readonly invalid = computed(() =>
    this.items().map((item) => {
      const value = item.trim();
      return value !== '' && !(this.kind() === 'origin' ? validOrigin(value) : validRedirectUri(value));
    }),
  );

  protected update(index: number, value: string): void {
    this.items.update((items) => items.map((item, i) => (i === index ? value : item)));
  }

  protected add(): void {
    this.items.update((items) => [...items, '']);
  }

  protected remove(index: number): void {
    this.items.update((items) => items.filter((_, i) => i !== index));
  }
}
