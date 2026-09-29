import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { SessionEntry } from '../../../core/api/models';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { describeUserAgent, deviceLabel } from '../../../core/util/user-agent';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { ConfirmService } from '../../../ui/confirm/confirm.service';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SwitchComponent } from '../../../ui/switch/switch.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { sessionMethodKey, sessionStateKey } from '../session-labels';

/**
 * /account/sessions - every browser signed in to the account, and the ones
 * that were.
 *
 * Ended sessions stay listed (with why they ended) because "where has my
 * account been" is the question this page answers, not only "where is it
 * now". The current one cannot be ended from here - that is signing out.
 */
@Component({
  selector: 'app-sessions',
  imports: [AppDatePipe, TPipe, AlertComponent, BadgeComponent, ButtonComponent, CardComponent, EmptyStateComponent, ErrorStateComponent, IconComponent, PageHeaderComponent, SwitchComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './sessions.component.html',
  styleUrl: './sessions.component.scss',
})
export class SessionsComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly loading = signal(true);
  protected readonly loadError = signal<string | null>(null);
  protected readonly sessions = signal<SessionEntry[]>([]);
  protected readonly failedAttempts = signal(0);
  protected readonly showEnded = signal(true);
  protected readonly endingId = signal<number | null>(null);
  protected readonly endingOthers = signal(false);

  protected readonly visible = computed(() => (this.showEnded() ? this.sessions() : this.sessions().filter((session) => session.active)));
  protected readonly hasEnded = computed(() => this.sessions().some((session) => !session.active));
  protected readonly othersActive = computed(() => this.sessions().filter((session) => session.active && !session.current).length);

  protected readonly methodKey = sessionMethodKey;
  protected readonly stateKey = sessionStateKey;

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.loadError.set(null);
    this._accountApi.sessions().subscribe({
      next: (response) => {
        this.sessions.set(response.sessions);
        this.failedAttempts.set(response.failed_since_last_login);
        this.loading.set(false);
      },
      error: (error: ApiError) => {
        this.loadError.set(this._errorText(error));
        this.loading.set(false);
      },
    });
  }

  protected device(session: SessionEntry): string {
    return deviceLabel(session.user_agent) ?? this._i18n.t('sessions.unknownBrowser');
  }

  protected mobile(session: SessionEntry): boolean {
    return describeUserAgent(session.user_agent).mobile;
  }

  protected async end(session: SessionEntry): Promise<void> {
    const sure = await this._confirm.ask({
      title: this._i18n.t('sessions.endTitle'),
      message: this._i18n.t('sessions.endText'),
      confirmLabel: this._i18n.t('sessions.end'),
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.endingId.set(session.id);
    this._accountApi.endSession(session.id).subscribe({
      next: () => {
        this.endingId.set(null);
        this._toast.success(this._i18n.t('sessions.endedOne'));
        this.load();
      },
      error: (error: ApiError) => {
        this.endingId.set(null);
        this._toast.error(this._errorText(error));
      },
    });
  }

  protected async endOthers(): Promise<void> {
    const sure = await this._confirm.ask({
      title: this._i18n.t('sessions.endOthersTitle'),
      message: this._i18n.t('sessions.endOthersText'),
      confirmLabel: this._i18n.t('sessions.endOthers'),
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.endingOthers.set(true);
    this._accountApi.endOtherSessions().subscribe({
      next: () => {
        this.endingOthers.set(false);
        this._toast.success(this._i18n.t('sessions.endedOthers'));
        this.load();
      },
      error: (error: ApiError) => {
        this.endingOthers.set(false);
        this._toast.error(this._errorText(error));
      },
    });
  }

  private _errorText(error: ApiError): string {
    return errorText(error, (key) => this._i18n.t(key));
  }
}
