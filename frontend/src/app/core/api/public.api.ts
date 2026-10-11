import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay } from 'rxjs';
import { PublicConfig } from './models';

/** What the sign-in pages read before a session exists. */
@Injectable({ providedIn: 'root' })
export class PublicApi {
  private readonly _http = inject(HttpClient);

  private _config$?: Observable<PublicConfig>;

  /**
   * The switches and limits the sign-in pages draw themselves from.
   *
   * Asked once per page load and shared: the login form, the register form and
   * the Google button all want it, and it only changes when an admin flips a
   * switch - which a reload picks up. A failure is not cached, so the retry on
   * an error state really asks again.
   */
  config(): Observable<PublicConfig> {
    if (!this._config$) {
      const request$ = this._http.get<PublicConfig>('/api/public/config').pipe(shareReplay({ bufferSize: 1, refCount: false }));
      this._config$ = request$;
      request$.subscribe({ error: () => (this._config$ = undefined) });
    }
    return this._config$;
  }
}
