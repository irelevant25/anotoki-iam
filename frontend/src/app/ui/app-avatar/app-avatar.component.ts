import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';

/**
 * An application's logo, or its initial when it has none.
 *
 * A logo that fails to load (a moved file, a URL the Content-Security-Policy
 * refuses) falls back to the initial rather than leaving a broken image. The
 * image is decorative - the application's name is always written next to it.
 */
@Component({
  selector: 'app-app-avatar',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[style.--avatar-size.px]': 'size()' },
  templateUrl: './app-avatar.component.html',
  styleUrl: './app-avatar.component.scss',
})
export class AppAvatarComponent {
  readonly name = input.required<string>();
  readonly logoUrl = input<string | null | undefined>(null);
  readonly size = input(40);

  protected readonly failed = signal(false);
  protected readonly initial = computed(() => (this.name().trim()[0] ?? '?').toUpperCase());
}
