import { ChangeDetectionStrategy, Component, OnInit, computed, inject, input, output, signal } from '@angular/core';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminUser, UpdateUserBody } from '../../../core/api/models';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CheckboxComponent } from '../../../ui/checkbox/checkbox.component';
import { DialogComponent } from '../../../ui/dialog/dialog.component';
import { SelectComponent } from '../../../ui/select/select.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { adminErrorText } from '../admin-errors';
import { languageOptions } from './language-options';

/**
 * The account's own fields. Only what changed is sent: the backend refuses an
 * empty change, and a field sent unchanged still reads as "changed" in the
 * audit log.
 */
@Component({
  selector: 'app-edit-user-dialog',
  imports: [AlertComponent, ButtonComponent, CheckboxComponent, DialogComponent, SelectComponent, TextFieldComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './edit-user-dialog.component.html',
  styleUrl: './edit-user-dialog.component.scss',
})
export class EditUserDialogComponent implements OnInit {
  readonly user = input.required<AdminUser>();
  readonly closed = output<AdminUser | null>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _i18n = inject(TranslationService);

  /** The languages on offer, and the account's own when it is no longer one of them. */
  protected readonly languageOptions = computed(() => languageOptions(this._i18n.languages(), this.user().language));

  protected readonly username = signal('');
  protected readonly email = signal('');
  protected readonly language = signal('en');
  protected readonly emailConfirmed = signal(false);
  protected readonly forceChange = signal(false);
  protected readonly usernameError = signal<string | null>(null);
  protected readonly emailError = signal<string | null>(null);
  protected readonly formError = signal<string | null>(null);
  protected readonly busy = signal(false);

  ngOnInit(): void {
    const user = this.user();
    this.username.set(user.username);
    this.email.set(user.email);
    this.language.set(user.language);
    this.emailConfirmed.set(user.email_confirmed);
    this.forceChange.set(user.force_password_change);
  }

  protected submit(): void {
    this.usernameError.set(null);
    this.emailError.set(null);
    this.formError.set(null);

    const user = this.user();
    const body: UpdateUserBody = {};
    const username = this.username().trim();
    const email = this.email().trim();

    if (!username) {
      this.usernameError.set('A username is needed.');
      return;
    }
    if (!email.includes('@')) {
      this.emailError.set('That does not look like an email address.');
      return;
    }

    if (username !== user.username) {
      body.username = username;
    }
    if (email !== user.email) {
      body.email = email;
    }
    if (this.language() !== user.language) {
      body.language = this.language();
    }
    if (this.emailConfirmed() !== user.email_confirmed) {
      body.email_confirmed = this.emailConfirmed();
    }
    if (this.forceChange() !== user.force_password_change) {
      body.force_password_change = this.forceChange();
    }

    if (Object.keys(body).length === 0) {
      this.closed.emit(null);
      return;
    }

    this.busy.set(true);
    this._adminApi.updateUser(user.id, body).subscribe({
      next: (updated) => {
        this.busy.set(false);
        this.closed.emit(updated);
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        const text = adminErrorText(error);
        if (error.code === 'username_taken' || error.code === 'invalid_username') {
          this.usernameError.set(text);
        } else if (error.code === 'email_taken' || error.code === 'invalid_email') {
          this.emailError.set(text);
        } else {
          this.formError.set(text);
        }
      },
    });
  }
}
