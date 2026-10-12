import { Injectable, effect, inject, signal } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { RouterStateSnapshot, TitleStrategy } from '@angular/router';
import { isAdminArea } from '../auth/next-path';
import { TranslationKey, en } from './en';
import { TranslationService } from './translation.service';

/** Between an admin page's title and the IAM's name, in the admin panel's English: "Users · Admin panel · anotoki account". */
const ADMIN_PANEL = 'Admin panel';

function isTranslationKey(value: string): value is TranslationKey {
  return Object.prototype.hasOwnProperty.call(en, value);
}

/**
 * The browser tab's title, in the anotoki family's form: the page first,
 * where a tab shows the start of it, then the site - "Security · anotoki
 * account" - and in the admin panel which part of the site it is: "Users ·
 * Admin panel · anotoki account", as every anotoki site's admin area says it
 * ("Results · Admin panel · anotoki survey").
 *
 * A route's `title` is a translation key on the translated pages and plain
 * English in the admin panel; either works here. The IAM's name is its own
 * string (`brand.account`), read in English in the admin panel, which the
 * translations keep English. The title follows the language too - switching
 * to Slovak renames the tab without a navigation.
 *
 * It is also how the translations hear where the page is (`noteAddress()`):
 * the service never injects the Router - the Router makes this strategy, and
 * this strategy reads the service.
 */
@Injectable({ providedIn: 'root' })
export class IamTitleStrategy extends TitleStrategy {
  private readonly _title = inject(Title);
  private readonly _i18n = inject(TranslationService);

  private readonly _page = signal<string | undefined>(undefined);
  private readonly _admin = signal(false);

  constructor() {
    super();
    effect(() => {
      const raw = this._page();
      const site = this._i18n.t('brand.account');
      const page = raw ? (isTranslationKey(raw) ? this._i18n.t(raw) : raw) : null;
      const parts = [page, this._admin() ? ADMIN_PANEL : null, site].filter((part): part is string => !!part);
      this._title.setTitle(parts.join(' · '));
    });
  }

  override updateTitle(snapshot: RouterStateSnapshot): void {
    this._i18n.noteAddress(snapshot.url);
    this._page.set(this.buildTitle(snapshot));
    this._admin.set(isAdminArea(snapshot.url));
  }
}
