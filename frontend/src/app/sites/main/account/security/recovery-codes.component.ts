import { ChangeDetectionStrategy, Component, computed, inject, input, model } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CopyButtonComponent } from '@anotoki/lib/ui/copy';
import { CheckboxComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { TPipe } from '../../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../../shared/i18n/translation.service';

/**
 * A fresh set of recovery codes, shown the one time they exist in the clear.
 *
 * The dialog around this stays open until `saved` is ticked: these are what
 * stands between a lost phone and a locked account, and a stray Escape should
 * not be what loses them. Copy and download are both offered because people
 * keep them in very different places.
 */
@Component({
  selector: 'app-recovery-codes',
  imports: [TPipe, AlertComponent, ButtonComponent, CheckboxComponent, CopyButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './recovery-codes.component.html',
  styleUrl: './recovery-codes.component.scss',
})
export class RecoveryCodesComponent {
  readonly codes = input.required<string[]>();
  readonly username = input('');
  readonly saved = model(false);

  private readonly _i18n = inject(TranslationService);

  protected readonly plain = computed(() => this.codes().join('\n'));

  protected download(): void {
    const text = [
      this._i18n.t('security.codes.fileTitle', { username: this.username() }),
      new Date().toISOString().slice(0, 10),
      '',
      ...this.codes(),
      '',
      this._i18n.t('security.codes.fileNote'),
      '',
    ].join('\n');

    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'anotoki-recovery-codes.txt';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
}
