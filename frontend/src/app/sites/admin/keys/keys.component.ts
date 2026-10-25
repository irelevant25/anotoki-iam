import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { AnotokiDatePipe } from '@anotoki/lib/ui/dates';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { SigningKey } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';

/** How long a retired key stays in the JWKS (the backend's KEY_RETIRED_GRACE). */
const RETIRED_GRACE_MS = 24 * 60 * 60 * 1000;

/**
 * /admin/keys - the RSA keys tokens are signed with.
 *
 * One key is active. Rotating makes a new one active and retires the old,
 * which stays published a day so tokens it already signed keep verifying
 * until they expire - nobody is signed out by a rotation.
 */
@Component({
  selector: 'app-keys',
  imports: [AnotokiDatePipe, BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './keys.component.html',
  styleUrl: './keys.component.scss',
})
export class KeysComponent {
  private readonly _adminApi = inject(AdminApi);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly keys = signal<SigningKey[] | null>(null);
  protected readonly loadError = signal<string | null>(null);
  protected readonly rotating = signal(false);
  protected readonly removing = signal<string | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loadError.set(null);
    this._adminApi.keys().subscribe({
      next: (keys) => this.keys.set(keys),
      error: (error: ApiError) => this.loadError.set(adminErrorText(error)),
    });
  }

  protected publishedUntil(key: SigningKey): string {
    if (!key.retired_at) {
      return '';
    }
    const until = new Date(key.retired_at).getTime() + RETIRED_GRACE_MS;
    return until > Date.now() ? 'Still in the JWKS for tokens it signed' : 'Out of its grace period - safe to remove';
  }

  protected async rotate(): Promise<void> {
    const sure = await this._confirm.ask({
      title: 'Rotate the signing key?',
      message:
        'A new key starts signing every token from now on. The current one is retired, but stays in the JWKS for a day, so tokens it already signed keep working until they expire. Nobody is signed out.',
      confirmLabel: 'Rotate now',
    });
    if (!sure) {
      return;
    }

    this.rotating.set(true);
    this._adminApi.rotateKey().subscribe({
      next: (rotated) => {
        this.rotating.set(false);
        this._toast.success(`${rotated.message}: ${rotated.kid}`);
        this.load();
      },
      error: (error: ApiError) => {
        this.rotating.set(false);
        this._toast.error(adminErrorText(error));
      },
    });
  }

  protected async remove(key: SigningKey): Promise<void> {
    const sure = await this._confirm.ask({
      title: 'Remove this retired key?',
      message: 'It leaves the JWKS. Any token it signed that has not expired yet stops verifying - wait out its day unless the key is compromised.',
      confirmLabel: 'Remove key',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.removing.set(key.kid);
    this._adminApi.deleteKey(key.kid).subscribe({
      next: () => {
        this.removing.set(null);
        this._toast.success('The key was removed.');
        this.load();
      },
      error: (error: ApiError) => {
        this.removing.set(null);
        this._toast.error(adminErrorText(error));
      },
    });
  }
}
