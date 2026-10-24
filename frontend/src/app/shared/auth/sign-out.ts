import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { ToastService } from '@anotoki/lib/ui/toast';
import { firstValueFrom } from 'rxjs';
import { ApiError } from '../../api/api-error';
import { LoginApi } from '../../api/login.api';
import { errorText } from '../i18n/error-text';
import { TranslationService } from '../i18n/translation.service';
import { SessionService } from './session.service';

/**
 * Sign out, as the IAM's two frames offer it - the account pages' bar (its
 * person's menu) and the admin panel's (its column, anotoki-lib 0.10.0): one
 * function, so the two cannot drift apart.
 *
 * Nothing is lost by it: the admin panel's unsaved strings wait in the tab for
 * the same administrator. A promise, so the bar's item and the frame's button
 * keep waiting (a spinner) until the server has answered; then the session is
 * gone here too and the page is the signed-out one. A failure leaves the
 * person signed in, and a toast says so.
 */
@Injectable({ providedIn: 'root' })
export class SignOutService {
  private readonly _loginApi = inject(LoginApi);
  private readonly _session = inject(SessionService);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _i18n = inject(TranslationService);

  signOut(): Promise<void> {
    return firstValueFrom(this._loginApi.logout()).then(
      () => {
        this._session.clear();
        void this._router.navigate(['/logout'], { state: { signedOut: true } });
      },
      (error: ApiError) => {
        this._toast.error(error.status === 0 ? errorText(error, (key) => this._i18n.t(key)) : this._i18n.t('shell.signOutFailed'));
      },
    );
  }
}
