import { DOCUMENT, Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, shareReplay } from 'rxjs';
import { PublicApi } from '../../../../api/public.api';

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

/** Google's script: the only address of Google's the pages ever load anything from, and only after a press. */
export const GIS_SCRIPT = 'https://accounts.google.com/gsi/client';

export interface GoogleButtonOptions {
  /** `signin_with`, `signup_with` or `continue_with` - Google words it, in its own translations. */
  text: 'signin_with' | 'signup_with' | 'continue_with';
  /** The page's language, so the button does not answer in the browser's. */
  locale: string;
  theme: 'light' | 'dark';
  width: number;
}

/**
 * Google Identity Services, fetched only when somebody asks for it.
 *
 * Ported from the genshin site. The client id comes from the API
 * (`GET /api/public/config`) rather than from the build, so switching Google
 * sign-in on or off is a setting on the server and not a rebuild - and with it
 * off no button is drawn at all.
 *
 * Nothing here runs by itself (the owner's decision of 2026-10-14): a page
 * shows the IAM's own button first (sites/main/common/google-button), and only a press on it
 * calls `load()` - so a visitor who never chooses Google has a browser that
 * never contacts Google. `drawButton()` draws Google's own button once the
 * script is there; it never fetches anything itself.
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
  private readonly _document = inject(DOCUMENT);

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
   * Puts Google's script on the page - once a page life: every later call,
   * from this button or the next page's, has the same answer.
   *
   * Called only from a press on the IAM's button. Resolves false rather than
   * throwing if the script cannot be fetched - blocked, offline, or an
   * extension in the way - or arrives without Google Identity Services in it
   * (a download cut short, an extension's stand-in), and forgets that attempt
   * (its element too), so a later press fetches it again. The rest of the
   * form works either way, which is the whole reason the other ways of
   * signing in stay available.
   */
  load(): Promise<boolean> {
    if (this._scriptLoading) {
      return this._scriptLoading;
    }

    this._scriptLoading = new Promise<boolean>((resolve) => {
      const view = this._document.defaultView;
      if (view?.google?.accounts?.id) {
        resolve(true);
        return;
      }

      const script = this._document.createElement('script');
      const forget = () => {
        script.remove();
        this._scriptLoading = undefined;
        resolve(false);
      };
      script.src = GIS_SCRIPT;
      script.async = true;
      script.onload = () => (view?.google?.accounts?.id ? resolve(true) : forget());
      script.onerror = forget;
      this._document.head.appendChild(script);
    });

    return this._scriptLoading;
  }

  /**
   * Draws Google's own button into `parent`, and calls back with the token -
   * false when Google's script is not there (load() first) or will not draw.
   *
   * Google's button for the account chooser, because only the rendered one
   * carries it; drawing it is what a press on the IAM's button asked for.
   */
  drawButton(parent: HTMLElement, clientId: string, options: GoogleButtonOptions, onCredential: (credential: string) => void): boolean {
    const google = this._document.defaultView?.google;
    if (!google?.accounts?.id) {
      return false;
    }

    try {
      // Re-initialised per button: the callback is global, and this is what
      // makes the most recently drawn button the one that owns it.
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
    } catch {
      parent.replaceChildren();
      return false;
    }

    this._handler = onCredential;
    return true;
  }

  /** Stops a button that has gone away from answering for one that has not. */
  release(onCredential: (credential: string) => void): void {
    if (this._handler === onCredential) {
      this._handler = null;
    }
  }
}
