import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { NavigationEnd, Router } from '@angular/router';
import { TopBarAction, TopBarBrand, TopBarComponent, TopBarLink, TopBarMenuItem, TopBarPerson } from '@anotoki/lib/shell';
import { ToastService } from '@anotoki/lib/ui/toast';
import { filter, firstValueFrom, map } from 'rxjs';
import { ApiError } from '../../core/api/api-error';
import { LoginApi } from '../../core/api/login.api';
import { SessionService } from '../../core/auth/session.service';
import { TranslationKey } from '../../core/i18n/en';
import { errorText } from '../../core/i18n/error-text';
import { TranslationService } from '../../core/i18n/translation.service';

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
 * the family's bar): the row with the five account links, the language, the
 * theme and the person's round button needs 1010 px in English and 1005 in
 * Slovak - plus a desktop browser's scrollbar (up to 17 px, which a media
 * query counts and the row does not have) and a little for a string reworded
 * in the panel. Above it only the person's name gives way (hidden up to
 * 1240 px, the bar's default of this plus 201).
 */
export const PHONE_BAR_MAX = 1040;

/**
 * The bar across the top of the account pages and the admin panel: the
 * anotoki family's one bar (@anotoki/lib/shell's `<anotoki-top-bar>`, whose
 * order, menus, keys and fold are the family's), worded and wired here - in
 * one row, the brand (the mark and "anotoki account" - "anotoki account ·
 * admin panel" in the admin panel - linking home); the account pages as
 * links; the language (on the account pages, which are translated - not in
 * the admin panel, which is English); the theme; and the person's menu
 * (name, address, role; the admin panel for the IAM's administrators, or back
 * to the account from it; Sign out) - or Sign in.
 *
 * The family's menu has "Your anotoki account" for the IAM's account page;
 * here that is this site, so the entry is left out: the account pages are the
 * bar's own links (Overview is their home), and in the admin panel the way to
 * them is the menu's "Back to your account".
 *
 * The admin panel keeps its own section navigation (the frame's side list, a
 * strip under the bar on a phone - admin-layout): thirteen sections are a
 * list, not a row of links. Its bar has no links of its own.
 *
 * The legal pages (layouts/legal-layout) are anybody's: their bar is the
 * account pages' - the brand, the language, the theme, the person's menu -
 * with the account pages as links only while somebody is signed in, and Sign
 * in otherwise (no row of links that would each be the sign-in page).
 *
 * Every word a reader of the account pages meets is a key; in the admin panel
 * the translations answer in English (`effectiveLanguage`), and what only the
 * panel shows is written here in English, as the panel's templates are. The
 * bar's own words (the menus' names, Sign out, the language's button) are the
 * database's strings too, through the kit's lookup (core/kit/kit-words.ts).
 *
 * A language chosen in the bar is the account's too, as the overview's select
 * makes it - the account's language outranks this browser's choice the next
 * time the pages load, and it is the language of the IAM's mails. That is the
 * family's translations' work (the shell's `languages` are its `forShell()`,
 * core/kit/kit.config.ts): the page switches once the words have come, then
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
   * Which part of the IAM the page belongs to: what the brand says and links
   * to, the links, and where the other part is - the account pages, the admin
   * panel, or a page anybody may read (the legal pages: the account's bar,
   * its links only for somebody signed in).
   */
  readonly area = input.required<'account' | 'admin' | 'public'>();

  private readonly _session = inject(SessionService);
  private readonly _loginApi = inject(LoginApi);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _i18n = inject(TranslationService);

  protected readonly phoneMax = PHONE_BAR_MAX;

  /**
   * "anotoki account"; in the admin panel the family's form for an admin area,
   * "anotoki {site} · admin panel" (the owner's, 2026-10-07): "anotoki account
   * · admin panel", its link named with the words shown. The site's word is
   * the account pages' own (`brand.area` - English here, as the panel reads
   * the English), so a rewording on the Translations page renames both. On a
   * phone the bar takes the short form: "account", "admin panel" small under it.
   */
  protected readonly brand = computed<TopBarBrand>(() => {
    const area = this._i18n.t('brand.area');
    return this.area() === 'admin' ? { area, panel: 'admin panel', link: '/admin', label: `anotoki ${area} admin panel - home` } : { area, link: '/account', label: this._i18n.t('brand.home') };
  });

  protected readonly links = computed<TopBarLink[]>(() =>
    this.area() === 'account' || (this.area() === 'public' && this._session.signedIn()) ? ACCOUNT_LINKS.map((link) => ({ id: link.path, label: this._i18n.t(link.key), path: link.path })) : [],
  );

  protected readonly person = computed<TopBarPerson | null>(() => {
    const me = this._session.user();
    return me ? { name: me.username, email: me.email, role: this._session.isAdmin() ? this._i18n.t('shell.roleAdmin') : null } : null;
  });

  /** Between the person and Sign out: the other part of the IAM - the admin panel for its administrators, or back from it. */
  protected readonly menuItems = computed<TopBarMenuItem[]>(() => {
    if (this.area() === 'admin') {
      return [{ id: 'account', label: 'Back to your account', icon: 'arrowLeft', path: '/account' }];
    }
    return this._session.isAdmin() ? [{ id: 'admin', label: this._i18n.t('shell.admin'), icon: 'shield', path: '/admin' }] : [];
  });

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

  /**
   * Signs out of the IAM - nothing is lost: the admin panel's unsaved strings
   * wait in the tab for the same administrator. A promise, so the bar keeps
   * the item waiting (a spinner) until the server has answered.
   */
  protected readonly signOut: TopBarAction = () =>
    firstValueFrom(this._loginApi.logout()).then(
      () => {
        this._session.clear();
        void this._router.navigate(['/logout'], { state: { signedOut: true } });
      },
      (error: ApiError) => {
        this._toast.error(error.status === 0 ? errorText(error, (key) => this._i18n.t(key)) : this._i18n.t('shell.signOutFailed'));
      },
    );
}
