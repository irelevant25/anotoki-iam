import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, shareReplay } from 'rxjs';
import { PublicApi } from '../api/public.api';

/** The sliver of Google Identity Services this uses. */
interface GoogleIdentityServices {
  accounts: {
    id: {
      initialize(config: { client_id: string; callback: (response: { credential: string }) => void; ux_mode?: 'popup' | 'redirect' }): void;
      renderButton(parent: HTMLElement, options: Record<string, unknown>): void;
    };
  };
}

declare global {
  interface Window {
    google?: GoogleIdentityServices;
  }
}

const GIS_SCRIPT = 'https://accounts.google.com/gsi/client';

export interface GoogleButtonOptions {
  /** `signin_with`, `signup_with` or `continue_with` - Google words it, in its own translations. */
  text: 'signin_with' | 'signup_with' | 'continue_with';
  /** The page's language, so the button does not answer in the browser's. */
  locale: string;
  theme: 'light' | 'dark';
  width: number;
}

/**
 * Loading Google Identity Services, and knowing whether to bother.
 *
 * Ported from the genshin site. The client id comes from the API
 * (`GET /api/public/config`) rather than from the build, so switching Google
 * sign-in on or off is a setting on the server and not a rebuild - and with it
 * off the button is simply never drawn.
 *
 * GIS has exactly one callback for the whole page: `initialize` sets it, and
 * every button rendered afterwards calls it. So the handler is held here and
 * whichever button was rendered last owns it. Only one button is ever on
 * screen at a time here, but that single global callback is why this is a
 * service and not something each button does for itself.
 */
@Injectable({ providedIn: 'root' })
export class GoogleSignInService {
  private readonly _publicApi = inject(PublicApi);

  private _clientId$?: Observable<string | null>;
  private _scriptLoading?: Promise<boolean>;
  private _handler: ((credential: string) => void) | null = null;

  /**
   * The client id this deployment uses, or null when Google sign-in is off.
   *
   * A config that could not be loaded means the same as one with Google off:
   * no button. The page around it has its own error state for the config, and
   * a missing Google button is not what somebody needs to be told about.
   */
  clientId(): Observable<string | null> {
    if (!this._clientId$) {
      this._clientId$ = this._publicApi.config().pipe(
        map((config) => (config.google_enabled && config.google_client_id ? config.google_client_id : null)),
        catchError(() => of(null)),
        shareReplay({ bufferSize: 1, refCount: false }),
      );
    }
    return this._clientId$;
  }

  /**
   * Draws Google's own button into `parent`, and calls back with the token.
   *
   * Google's button rather than one of ours, because Google's branding terms
   * ask for it and because the rendered one carries the account chooser.
   */
  async renderButton(parent: HTMLElement, clientId: string, options: GoogleButtonOptions, onCredential: (credential: string) => void): Promise<boolean> {
    const loaded = await this._loadScript();
    const google = window.google;

    if (!loaded || !google) {
      return false;
    }

    this._handler = onCredential;

    // Re-initialised per button: the callback is global, and this is what makes
    // the most recently drawn button the one that owns it.
    google.accounts.id.initialize({
      client_id: clientId,
      ux_mode: 'popup',
      callback: (response) => this._handler?.(response.credential),
    });

    // Drawing again (a theme or language change) replaces the old iframe
    // rather than stacking a second one under it.
    parent.replaceChildren();
    google.accounts.id.renderButton(parent, {
      type: 'standard',
      theme: options.theme === 'dark' ? 'filled_black' : 'outline',
      size: 'large',
      shape: 'pill',
      text: options.text,
      logo_alignment: 'left',
      locale: options.locale,
      width: options.width,
    });

    return true;
  }

  /** Stops a button that has gone away from answering for one that has not. */
  release(onCredential: (credential: string) => void): void {
    if (this._handler === onCredential) {
      this._handler = null;
    }
  }

  /**
   * Puts Google's script on the page, once.
   *
   * Resolves false rather than throwing if it cannot be fetched - blocked,
   * offline, or an extension in the way. The caller draws nothing and the rest
   * of the form still works, which is the whole reason the other ways of
   * signing in stay available.
   */
  private _loadScript(): Promise<boolean> {
    if (this._scriptLoading) {
      return this._scriptLoading;
    }

    this._scriptLoading = new Promise<boolean>((resolve) => {
      if (window.google?.accounts?.id) {
        resolve(true);
        return;
      }

      const script = document.createElement('script');
      script.src = GIS_SCRIPT;
      script.async = true;
      script.defer = true;
      script.onload = () => resolve(!!window.google?.accounts?.id);
      script.onerror = () => {
        // Forget the failed attempt, so a later page can try again.
        this._scriptLoading = undefined;
        resolve(false);
      };
      document.head.appendChild(script);
    });

    return this._scriptLoading;
  }
}
