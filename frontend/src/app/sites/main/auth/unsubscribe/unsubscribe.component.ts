import { ChangeDetectionStrategy, Component, OnInit, inject, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AutofocusDirective } from '@anotoki/lib/ui/autofocus';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { ApiError } from '../../../../api/api-error';
import { LoginApi } from '../../../../api/login.api';
import { errorText } from '../../../../shared/i18n/error-text';
import { TPipe } from '../../../../shared/i18n/t.pipe';
import { TranslationService } from '../../../../shared/i18n/translation.service';
import { AuthCardComponent } from '../auth-card/auth-card.component';
import { dropQueryParam } from '../auth-helpers';

type Step = 'ask' | 'done' | 'failed' | 'missing';

/**
 * /unsubscribe?token= - where the link at the end of every announcement lands.
 *
 * It asks before anything is switched off: a mail program or a virus scanner
 * opens every link in a mail to look at it, and looking must not stop
 * anybody's news. No session is needed - the token names the account - and
 * the token is taken out of the address bar at once, like a confirmation
 * link's, so it does not stay in the history or travel on with a copied URL.
 */
@Component({
  selector: 'app-unsubscribe',
  imports: [RouterLink, TPipe, AuthCardComponent, AutofocusDirective, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './unsubscribe.component.html',
  styleUrl: './unsubscribe.component.scss',
})
export class UnsubscribeComponent implements OnInit {
  /** `?token=` from the mailed link. Held here, never left in the address bar. */
  readonly token = input<string | undefined>();

  private readonly _loginApi = inject(LoginApi);
  private readonly _i18n = inject(TranslationService);

  private _token = '';

  protected readonly step = signal<Step>('ask');
  protected readonly busy = signal(false);
  protected readonly failure = signal('');

  ngOnInit(): void {
    this._token = this.token() ?? '';
    dropQueryParam('token');

    if (!this._token) {
      this.step.set('missing');
    }
  }

  protected stop(): void {
    this.busy.set(true);
    this._loginApi.stopNews(this._token).subscribe({
      next: () => {
        this.busy.set(false);
        this.step.set('done');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.failure.set(error.code === 'invalid_token' ? this._i18n.t('unsubscribe.failedText') : errorText(error, (key) => this._i18n.t(key)));
        this.step.set('failed');
      },
    });
  }
}
