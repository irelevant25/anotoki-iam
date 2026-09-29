import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminUser, SetPasswordResponse } from '../../../core/api/models';
import { GENERATED_PASSWORD_LENGTH, randomPassword } from '../../../core/util/random-password';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CheckboxComponent } from '../../../ui/checkbox/checkbox.component';
import { CopyButtonComponent } from '../../../ui/copy-button/copy-button.component';
import { DialogComponent } from '../../../ui/dialog/dialog.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { adminErrorText } from '../admin-errors';

/**
 * A new password without the old one - what "I am locked out" needs.
 *
 * The field is plain text, not a password field: the whole point is to read
 * it out or pass it on, and the generator's output has to be seen to be
 * copied. Every session of the account ends when it is saved.
 */
@Component({
  selector: 'app-set-password-dialog',
  imports: [AlertComponent, ButtonComponent, CheckboxComponent, CopyButtonComponent, DialogComponent, IconComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './set-password-dialog.component.html',
  styleUrl: './set-password-dialog.component.scss',
})
export class SetPasswordDialogComponent {
  readonly user = input.required<AdminUser>();
  readonly passwordMin = input(8);
  readonly closed = output<SetPasswordResponse | null>();

  private readonly _adminApi = inject(AdminApi);

  protected readonly password = signal('');
  protected readonly forceChange = signal(true);
  protected readonly passwordError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected generate(): void {
    this.password.set(randomPassword(Math.max(GENERATED_PASSWORD_LENGTH, this.passwordMin())));
    this.passwordError.set(null);
  }

  protected submit(): void {
    this.passwordError.set(null);
    this.formError.set(null);
    if (this.password().length < this.passwordMin()) {
      this.passwordError.set(`At least ${this.passwordMin()} characters.`);
      return;
    }

    this.busy.set(true);
    this._adminApi.setUserPassword(this.user().id, { password: this.password(), force_password_change: this.forceChange() }).subscribe({
      next: (response) => {
        this.busy.set(false);
        this.closed.emit(response);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        if (error.code === 'invalid_password') {
          this.passwordError.set(adminErrorText(error));
        } else {
          this.formError.set(adminErrorText(error));
        }
      },
    });
  }
}
