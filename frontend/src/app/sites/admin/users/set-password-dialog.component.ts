import { ChangeDetectionStrategy, Component, inject, input, output, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CopyButtonComponent } from '@anotoki/lib/ui/copy';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { CheckboxComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AdminUser, SetPasswordResponse } from '../../../api/models';
import { GENERATED_PASSWORD_LENGTH, randomPassword } from '../shared/random-password';
import { adminErrorText } from '../shared/admin-errors';

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
