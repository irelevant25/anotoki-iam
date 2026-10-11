import { ChangeDetectionStrategy, Component, OnInit, booleanAttribute, inject, input, signal } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { AutofocusDirective } from '@anotoki/lib/ui/autofocus';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { ApiError } from '../../../core/api/api-error';
import { LoginApi } from '../../../core/api/login.api';
import { RequestApplication } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';

type Step = 'loading' | 'ask' | 'done' | 'expired';

/** The IAM's own client id: a logout request it owns has no site to go back to but this one. */
const IAM_CLIENT_ID = 'iam';

/**
 * /logout - signing out, and confirming a sign-out an application asked for.
 *
 * `?request=` is an application's logout that came without a verifiable ID
 * token: a link on somebody else's page could have sent the person here, so
 * they are asked. `?done=1` is where the backend lands once a sign-out has
 * already happened. Coming from the account menu, the sign-out has happened
 * too, and the page only says so.
 */
@Component({
  selector: 'app-logout',
  imports: [RouterLink, TPipe, AuthCardComponent, AlertComponent, AutofocusDirective, ButtonComponent, IconComponent, SpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './logout.component.html',
  styleUrl: './logout.component.scss',
})
export class LogoutComponent implements OnInit {
  /** `?request=` - an application's logout waiting to be confirmed. */
  readonly request = input<string | undefined>();
  /** `?done=1` - the sign-out already happened. */
  readonly done = input(false, { transform: booleanAttribute });

  private readonly _loginApi = inject(LoginApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _router = inject(Router);

  protected readonly step = signal<Step>('loading');
  protected readonly application = signal<RequestApplication | null>(null);
  protected readonly busy = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly signedIn = this._session.signedIn;

  ngOnInit(): void {
    const signedOutFromMenu = (window.history.state as { signedOut?: boolean } | null)?.signedOut === true;

    if (this.done() || signedOutFromMenu) {
      this._session.clear();
      this.step.set('done');
      return;
    }

    const id = this.request();
    if (id) {
      this._loginApi.logoutRequest(id).subscribe({
        next: (logout) => {
          this.application.set(logout.application);
          this.step.set('ask');
        },
        error: () => {
          this._session.probe().subscribe({ next: () => this.step.set('expired'), error: () => this.step.set('expired') });
        },
      });
      return;
    }

    // Opened on its own: ask only if there is anything to sign out of.
    this._session.probe().subscribe({
      next: (user) => this.step.set(user ? 'ask' : 'done'),
      error: () => this.step.set('ask'),
    });
  }

  protected signOut(): void {
    this.busy.set(true);
    this.error.set(null);
    const id = this.step() === 'ask' ? this.request() : undefined;

    this._loginApi.logout(id).subscribe({
      next: (response) => {
        this._session.clear();
        if (response.redirect) {
          window.location.assign(response.redirect);
          return;
        }
        this.busy.set(false);
        this.application.set(null);
        this.step.set('done');
      },
      error: (error: ApiError) => {
        this.busy.set(false);
        this.error.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  /** "Stay signed in": back to the application's front door - or to the account, for the IAM's own requests. */
  protected stay(): void {
    const app = this.application();
    if (app && app.client_id !== IAM_CLIENT_ID && app.home_url) {
      window.location.assign(app.home_url);
    } else {
      void this._router.navigateByUrl('/account');
    }
  }
}
