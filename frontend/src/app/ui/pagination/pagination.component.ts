import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { ButtonComponent } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';

/**
 * "51-100 of 230", with previous and next.
 *
 * Only the admin console pages through anything, and the admin console is
 * English, so the words are written here rather than translated.
 */
@Component({
  selector: 'app-pagination',
  imports: [ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './pagination.component.html',
  styleUrl: './pagination.component.scss',
})
export class PaginationComponent {
  readonly page = input.required<number>();
  readonly pageSize = input.required<number>();
  readonly total = input.required<number>();
  readonly busy = input(false);

  readonly pageChange = output<number>();

  protected readonly pages = computed(() => Math.max(1, Math.ceil(this.total() / Math.max(1, this.pageSize()))));
  protected readonly first = computed(() => (this.page() - 1) * this.pageSize() + 1);
  protected readonly last = computed(() => Math.min(this.total(), this.page() * this.pageSize()));
}
