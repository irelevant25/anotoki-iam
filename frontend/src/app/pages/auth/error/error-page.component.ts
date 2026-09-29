import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { safeNext } from '../../../core/auth/next-path';
import { TranslationKey } from '../../../core/i18n/en';
import { TPipe } from '../../../core/i18n/t.pipe';
import { AuthCardComponent } from '../../../layouts/auth-card/auth-card.component';
import { AutofocusDirective } from '../../../ui/autofocus.directive';
import { ButtonComponent } from '../../../ui/button/button.component';
import { IconComponent } from '../../../ui/icon/icon.component';

interface ErrorCopy {
  title: TranslationKey;
  text: TranslationKey;
  /** A fault that may be gone on the next try, rather than a request that will fail the same way again. */
  retryable: boolean;
}

const COPY: Record<string, ErrorCopy> = {
  invalid_client: { title: 'errorPage.invalidClientTitle', text: 'errorPage.invalidClientText', retryable: false },
  invalid_redirect_uri: { title: 'errorPage.invalidRedirectTitle', text: 'errorPage.invalidRedirectText', retryable: false },
  application_disabled: { title: 'errorPage.appDisabledTitle', text: 'errorPage.appDisabledText', retryable: false },
  invalid_request: { title: 'errorPage.invalidRequestTitle', text: 'errorPage.invalidRequestText', retryable: false },
  server_error: { title: 'errorPage.serverTitle', text: 'errorPage.serverText', retryable: true },
  network: { title: 'errorPage.networkTitle', text: 'errorPage.networkText', retryable: true },
};

const OTHER: ErrorCopy = { title: 'errorPage.otherTitle', text: 'errorPage.otherText', retryable: true };

/**
 * /error?code= - where the IAM sends somebody it cannot send back.
 *
 * The authorize endpoint lands here when it cannot trust the application's
 * redirect address (an unknown client, an unregistered URI, a switched-off
 * application), and the session guard does when the server cannot be reached.
 * The code is shown too, small, for whoever gets asked about it.
 */
@Component({
  selector: 'app-error-page',
  imports: [RouterLink, TPipe, AuthCardComponent, AutofocusDirective, ButtonComponent, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './error-page.component.html',
  styleUrl: './error-page.component.scss',
})
export class ErrorPageComponent {
  readonly code = input<string | undefined>();
  readonly next = input<string | undefined>();

  private readonly _router = inject(Router);

  protected readonly copy = computed(() => COPY[this.code() ?? ''] ?? OTHER);

  protected retry(): void {
    void this._router.navigateByUrl(safeNext(this.next()));
  }
}
