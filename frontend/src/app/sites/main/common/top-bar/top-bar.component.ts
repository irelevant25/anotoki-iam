import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router } from '@angular/router';
import { TopBarAction, TopBarBrand, TopBarComponent, TopBarLink, TopBarMenuItem, TopBarPerson } from '@anotoki/lib/shell';
import { filter, map } from 'rxjs';
import { SessionService } from '../../../../shared/auth/session.service';
import { SignOutService } from '../../../../shared/auth/sign-out';
import { TranslationKey } from '../../../../shared/i18n/en';
import { TranslationService } from '../../../../shared/i18n/translation.service';

/** A section in the bar: its words (a key) and where it goes. */
export interface BarLink {
  key: TranslationKey;
  path: string;
}

/** The account pages: the IAM's own sections, and the only links the bar has. */
export const ACCOUNT_LINKS: readonly BarLink[] = [
  { key: 'account.nav.overview', path: '/account' },
  { key: 'account.nav.security', path: '/account/security' },
  { key: 'account.nav.sessions', path: '/account/sessions' },
  { key: 'account.nav.applications', path: '/account/applications' },
  { key: 'account.nav.tokens', path: '/account/tokens' },
];

/**
 * Up to this width the bar is a phone's: the links and the person's menu fold
 * into one menu button. Measured, not guessed (headless Edge, 2026-10-10, on
 * the family's bar; again on 2026-10-24, its admin area gone, the same): the
 * row with the five account links, the language, the theme and the person's
 * round button needs 1010 px in English and 1005 in Slovak - plus a desktop
 * browser's scrollbar (up to 17 px, which a media
 * query counts and the row does not have) and a little for a string reworded
 * in the panel. Above it only the person's name gives way (hidden up to
 * 1240 px, the bar's default of this plus 201).
 */
export const PHONE_BAR_MAX = 1040;

/**
 * The bar across the top of the account pages and the legal pages: the
 * anotoki family's one bar (@anotoki/lib/shell's `<anotoki-top-bar>`, whose
 * order, menus, keys and fold are the family's), worded and wired here - in
 * one row, the brand (the mark and "anotoki account", linking home); the
 * account pages as links; the language; the theme; and the person's menu
 * (name, address, role; the admin panel for the IAM's administrators; Sign
 * out) - or Sign in.
 *
 * The family's menu has "Your anotoki account" for the IAM's account page;
 * here that is this site, so the entry is left out: the account pages are the
 * bar's own links (Overview is their home).
 *
 * The admin panel has no bar: since anotoki-lib 0.10.0 it is in the family's
 * admin frame, a column of its own (sites/admin/app.component), so it is never
 * mistaken for these pages. Sign out is the same there as here
 * (SignOutService).
 *
 * The legal pages (sites/main/legal/legal-layout) are anybody's: their bar is the
 * account pages' - the brand, the language, the theme, the person's menu -
 * with the account pages as links only while somebody is signed in, and Sign
 * in otherwise (no row of links that would each be the sign-in page).
 *
 * Every word a reader of the bar meets is a key. The bar's own words (the
 * menus' names, Sign out, the language's button) are the database's strings
 * too, through the kit's lookup (shared/kit/kit-words.ts).
 *
 * A language chosen in the bar is the account's too, as the overview's select
 * makes it - the account's language outranks this browser's choice the next
 * time the pages load, and it is the language of the IAM's mails. That is the
 * family's translations' work (the shell's `languages` are its `forShell()`,
 * shared/kit/kit.config.ts): the page switches once the words have come, then
 * the choice is saved - one save at a time, and the latest choice last, so
 * two quick choices cannot leave the account on a language the page no
 * longer shows. A refusal leaves the page in the chosen language for this
 * visit, and the note under the language's button says so. The answer is the
 * session's account - unless Sign out came first: then it signs nobody back
 * in (SessionService.setFrom()).
 */
@Component({
  selector: 'app-top-bar',
  imports: [TopBarComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './top-bar.component.html',
  styleUrl: './top-bar.component.scss',
})
export class AppTopBarComponent {
  /**
   * Which part of the IAM the page belongs to: the account pages, or a page
   * anybody may read (the legal pages: the account's bar, its links only for
   * somebody signed in).
   */
  readonly area = input.required<'account' | 'public'>();

  private readonly _session = inject(SessionService);
  private readonly _signOut = inject(SignOutService);
  private readonly _router = inject(Router);
  private readonly _i18n = inject(TranslationService);

  protected readonly phoneMax = PHONE_BAR_MAX;

  /** "anotoki account", linking home - the site's word the account pages' own (`brand.area`), reworded on the Translations page like any other. */
  protected readonly brand = computed<TopBarBrand>(() => ({ area: this._i18n.t('brand.area'), link: '/account', label: this._i18n.t('brand.home') }));

  protected readonly links = computed<TopBarLink[]>(() =>
    this.area() === 'account' || this._session.signedIn() ? ACCOUNT_LINKS.map((link) => ({ id: link.path, label: this._i18n.t(link.key), path: link.path })) : [],
  );

  protected readonly person = computed<TopBarPerson | null>(() => {
    const me = this._session.user();
    return me ? { name: me.username, email: me.email, role: this._session.isAdmin() ? this._i18n.t('shell.roleAdmin') : null } : null;
  });

  /** Between the person and Sign out: the admin panel, for its administrators. */
  protected readonly menuItems = computed<TopBarMenuItem[]>(() => (this._session.isAdmin() ? [{ id: 'admin', label: this._i18n.t('shell.admin'), icon: 'shield', path: '/admin' }] : []));

  /** The address of the page, without its query: where Sign in comes back to. */
  private readonly _path = toSignal(
    this._router.events.pipe(
      filter((event) => event instanceof NavigationEnd),
      map(() => this._router.url),
    ),
    { initialValue: this._router.url },
  );

  /** To the sign-in page, coming back here - nothing to wait for. */
  protected readonly signIn: TopBarAction = () => {
    void this._router.navigate(['/login'], { queryParams: { next: this._path().split(/[?#]/)[0] || '/' } });
  };

  /** Signs out of the IAM, as the admin panel's frame does (SignOutService): a promise, so the bar keeps the item waiting until the server has answered. */
  protected readonly signOut: TopBarAction = () => this._signOut.signOut();
}
