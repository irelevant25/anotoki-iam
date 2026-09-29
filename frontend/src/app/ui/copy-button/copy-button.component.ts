import { ChangeDetectionStrategy, Component, DestroyRef, booleanAttribute, inject, input, signal } from '@angular/core';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { ButtonComponent, ButtonSize, ButtonVariant } from '../button/button.component';
import { IconComponent } from '../icon/icon.component';
import { ToastService } from '../toast/toast.service';

/**
 * Copies a value, then says so for two seconds - on the button, and to a
 * screen reader through a polite live region beside it.
 *
 * The Clipboard API needs a secure context, which both https and localhost
 * are. The old execCommand route stays as a fallback for anything else, and if
 * both fail the person is told to copy by hand rather than left believing it
 * worked.
 */
@Component({
  selector: 'app-copy-button',
  imports: [ButtonComponent, IconComponent, TPipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './copy-button.component.html',
  styleUrl: './copy-button.component.scss',
})
export class CopyButtonComponent {
  readonly text = input.required<string>();
  readonly label = input<string | null>(null);
  readonly variant = input<ButtonVariant>('secondary');
  readonly size = input<ButtonSize>('sm');
  readonly iconOnly = input(false, { transform: booleanAttribute });

  protected readonly copied = signal(false);

  private readonly _toast = inject(ToastService);
  private readonly _i18n = inject(TranslationService);
  private _timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    inject(DestroyRef).onDestroy(() => this._timer && clearTimeout(this._timer));
  }

  protected async copy(): Promise<void> {
    const ok = await copyText(this.text());
    if (!ok) {
      this._toast.error(this._i18n.t('ui.copyFailed'));
      return;
    }

    this.copied.set(true);
    if (this._timer) {
      clearTimeout(this._timer);
    }
    this._timer = setTimeout(() => this.copied.set(false), 2000);
  }
}

export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch {
    // Refused (permissions, an unfocused document): try the old way below.
  }

  try {
    const area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    const ok = document.execCommand('copy');
    area.remove();
    return ok;
  } catch {
    return false;
  }
}
