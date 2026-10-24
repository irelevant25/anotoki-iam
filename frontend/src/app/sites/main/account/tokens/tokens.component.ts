import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { CopyButtonComponent } from '@anotoki/lib/ui/copy';
import { ConfirmService, DialogComponent } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { SelectComponent, SelectOption, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { forkJoin } from 'rxjs';
import { AccountApi } from '../../../../api/account.api';
import { ApiError } from '../../../../api/api-error';
import { AccountApplication, CreatedToken, PersonalToken } from '../../../../api/models';
import { AppDatePipe } from '../../../../shared/i18n/app-date.pipe';
import { TranslationKey } from '../../../../shared/i18n/en';
import { errorText } from '../../../../shared/i18n/error-text';
import { TPipe } from '../../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../../shared/i18n/translation.service';

type TokenState = 'active' | 'expired' | 'revoked';

const EXPIRY_OPTIONS: { days: number; label: TranslationKey }[] = [
  { days: 7, label: 'tokens.new.week' },
  { days: 30, label: 'tokens.new.month' },
  { days: 90, label: 'tokens.new.quarter' },
  { days: 365, label: 'tokens.new.year' },
];

/**
 * /account/tokens - personal access tokens for scripts.
 *
 * A token is bound to one application and exchanged at the token endpoint for
 * ordinary access tokens, which carry the account's roles as they are at that
 * moment. It is shown once, when it is made; afterwards only its name and
 * dates are known here, and the backend keeps only a hash.
 */
@Component({
  selector: 'app-tokens',
  imports: [
    AppDatePipe,
    TPipe,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    CopyButtonComponent,
    DialogComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    TextFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './tokens.component.html',
  styleUrl: './tokens.component.scss',
})
export class TokensComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly tokens = signal<PersonalToken[]>([]);
  protected readonly applications = signal<AccountApplication[]>([]);
  protected readonly revokingId = signal<number | null>(null);

  // ── The create dialog ──────────────────────────────────────────────────────
  protected readonly creating = signal(false);
  protected readonly name = signal('');
  protected readonly clientId = signal('');
  protected readonly days = signal('30');
  protected readonly nameError = signal<string | null>(null);
  protected readonly appError = signal<string | null>(null);
  protected readonly createError = signal<string | null>(null);
  protected readonly saving = signal(false);

  /** The one moment the token exists outside the backend's hash. */
  protected readonly created = signal<CreatedToken | null>(null);

  protected readonly usable = computed(() => this.applications().filter((app) => app.can_use));
  protected readonly appOptions = computed<SelectOption[]>(() => this.usable().map((app) => ({ value: app.client_id, label: app.name })));
  protected readonly expiryOptions = computed<SelectOption[]>(() => EXPIRY_OPTIONS.map((option) => ({ value: String(option.days), label: this._i18n.t(option.label) })));

  /** The exchange a script makes, with this installation's own address and the new token in it. */
  protected readonly curlExample = computed(() => {
    const created = this.created();
    if (!created) {
      return '';
    }
    return [
      `curl -s -X POST ${window.location.origin}/api/oidc/token \\`,
      `  -d grant_type=refresh_token \\`,
      `  -d client_id=${created.entry.client_id} \\`,
      `  -d refresh_token=${created.token}`,
    ].join('\n');
  });

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    forkJoin({ tokens: this._accountApi.tokens(), applications: this._accountApi.applications() }).subscribe({
      next: ({ tokens, applications }) => {
        this.tokens.set(tokens);
        this.applications.set(applications);
        this.loading.set(false);
      },
      error: (error: ApiError) => {
        this.loadError.set(this._errorText(error));
        this.loading.set(false);
      },
    });
  }

  protected state(token: PersonalToken): TokenState {
    if (token.revoked_at) {
      return 'revoked';
    }
    return new Date(token.expires_at).getTime() <= Date.now() ? 'expired' : 'active';
  }

  protected openCreate(): void {
    this.name.set('');
    this.clientId.set(this.usable()[0]?.client_id ?? '');
    this.days.set('30');
    this.nameError.set(null);
    this.appError.set(null);
    this.createError.set(null);
    this.creating.set(true);
  }

  protected create(): void {
    this.nameError.set(null);
    this.appError.set(null);
    this.createError.set(null);

    const name = this.name().trim();
    if (!name) {
      this.nameError.set(this._i18n.t('common.required'));
    }
    if (!this.clientId()) {
      this.appError.set(this._i18n.t('common.required'));
    }
    if (this.nameError() || this.appError()) {
      return;
    }

    this.saving.set(true);
    this._accountApi.createToken({ name, client_id: this.clientId(), expires_in_days: Number(this.days()) }).subscribe({
      next: (created) => {
        this.saving.set(false);
        this.creating.set(false);
        this.created.set(created);
        this.load();
      },
      error: (error: ApiError) => {
        this.saving.set(false);
        this.createError.set(this._errorText(error));
      },
    });
  }

  protected async revoke(token: PersonalToken): Promise<void> {
    const sure = await this._confirm.ask({
      title: this._i18n.t('tokens.revokeTitle', { name: token.name }),
      message: this._i18n.t('tokens.revokeText'),
      confirmLabel: this._i18n.t('tokens.revoke'),
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.revokingId.set(token.id);
    this._accountApi.revokeToken(token.id).subscribe({
      next: () => {
        this.revokingId.set(null);
        this._toast.success(this._i18n.t('tokens.revoked'));
        this.load();
      },
      error: (error: ApiError) => {
        this.revokingId.set(null);
        this._toast.error(this._errorText(error));
      },
    });
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this._i18n.t(key));
  }
}
