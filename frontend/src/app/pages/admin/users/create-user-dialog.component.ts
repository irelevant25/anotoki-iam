import { ChangeDetectionStrategy, Component, computed, inject, input, output, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { DialogComponent } from '@anotoki/lib/ui/dialog';
import { CheckboxComponent, SelectComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminUser, FilterApplication } from '../../../core/api/models';
import { TranslationService } from '../../../core/i18n/translation.service';
import { GENERATED_PASSWORD_LENGTH, randomPassword } from '../../../core/util/random-password';
import { adminErrorText } from '../admin-errors';
import { languageOptions } from './language-options';
import { RolePickerComponent, grantFromKey } from './role-picker.component';

/**
 * A new account, made by hand for somebody who should not have to register.
 *
 * The backend marks it confirmed - an admin typed the address in - and the
 * password is one to hand over, so "choose a new one at the first sign-in" is
 * ticked unless the person is in the room choosing it themselves.
 *
 * MEMBER is not offered: the database gives every new account MEMBER in every
 * application, so the role picker leaves it out and counts it as held.
 */
@Component({
  selector: 'app-create-user-dialog',
  imports: [AlertComponent, ButtonComponent, CheckboxComponent, DialogComponent, IconComponent, SelectComponent, TextFieldComponent, RolePickerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './create-user-dialog.component.html',
  styleUrl: './create-user-dialog.component.scss',
})
export class CreateUserDialogComponent {
  readonly applications = input.required<FilterApplication[]>();
  readonly passwordMin = input(8);
  /** The new account, or null when the dialog was closed without making one. */
  readonly closed = output<AdminUser | null>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _i18n = inject(TranslationService);

  /** The languages on offer - the ones the server accepts for a new account. */
  protected readonly languageOptions = computed(() => languageOptions(this._i18n.languages()));

  protected readonly username = signal('');
  protected readonly email = signal('');
  protected readonly password = signal('');
  protected readonly language = signal<string>('en');
  protected readonly forceChange = signal(true);
  protected readonly roles = signal<string[]>([]);

  protected readonly usernameError = signal<string | null>(null);
  protected readonly emailError = signal<string | null>(null);
  protected readonly passwordError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);

  protected generate(): void {
    this.password.set(randomPassword(Math.max(GENERATED_PASSWORD_LENGTH, this.passwordMin())));
    this.passwordError.set(null);
  }

  protected submit(): void {
    this.usernameError.set(null);
    this.emailError.set(null);
    this.passwordError.set(null);
    this.formError.set(null);

    const username = this.username().trim();
    const email = this.email().trim();
    const password = this.password();

    if (!username) {
      this.usernameError.set('A username is needed.');
    }
    if (!email.includes('@')) {
      this.emailError.set('That does not look like an email address.');
    }
    if (password.length < this.passwordMin()) {
      this.passwordError.set(`At least ${this.passwordMin()} characters.`);
    }
    if (this.usernameError() || this.emailError() || this.passwordError()) {
      return;
    }

    this.busy.set(true);
    this._adminApi
      .createUser({
        username,
        email,
        password,
        language: this.language(),
        force_password_change: this.forceChange(),
        roles: this.roles().map(grantFromKey),
      })
      .subscribe({
        next: (user) => {
          this.busy.set(false);
          this.closed.emit(user);
        },
        error: (error: ApiError) => {
          this.busy.set(false);
          const text = adminErrorText(error);
          if (error.code === 'username_taken' || error.code === 'invalid_username') {
            this.usernameError.set(text);
          } else if (error.code === 'email_taken' || error.code === 'invalid_email') {
            this.emailError.set(text);
          } else if (error.code === 'invalid_password') {
            this.passwordError.set(text);
          } else {
            this.formError.set(text);
          }
        },
      });
  }
}
