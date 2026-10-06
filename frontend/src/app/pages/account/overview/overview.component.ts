import { ChangeDetectionStrategy, Component, computed, inject, linkedSignal, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { SessionService } from '../../../core/auth/session.service';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { IconComponent, IconName } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SelectComponent, SelectOption } from '../../../ui/select/select.component';
import { SwitchComponent } from '../../../ui/switch/switch.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { ThemeToggleComponent } from '../../../ui/theme-toggle/theme-toggle.component';
import { ToastService } from '../../../ui/toast/toast.service';

interface QuickLink {
  link: string;
  icon: IconName;
  title: TranslationKey;
  text: TranslationKey;
}

const QUICK_LINKS: QuickLink[] = [
  { link: '/account/security', icon: 'shield', title: 'account.nav.security', text: 'overview.quickSecurity' },
  { link: '/account/sessions', icon: 'laptop', title: 'account.nav.sessions', text: 'overview.quickSessions' },
  { link: '/account/applications', icon: 'grid', title: 'account.nav.applications', text: 'overview.quickApplications' },
  { link: '/account/tokens', icon: 'terminal', title: 'account.nav.tokens', text: 'overview.quickTokens' },
];

/**
 * /account - who the account is, and the way to everything else.
 *
 * The username is edited in place. The language is saved the moment it is
 * chosen: the page switches to it first - as soon as its words have come - and
 * the account is told after, so "saved" is already said in the new language.
 * Which languages there are comes from the database, with the words.
 *
 * The news from the sites - the announcements administrators send - is
 * switched here, the same switch the link at the end of every announcement
 * turns off.
 */
@Component({
  selector: 'app-overview',
  imports: [
    RouterLink,
    AppDatePipe,
    TPipe,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    IconComponent,
    PageHeaderComponent,
    SelectComponent,
    SwitchComponent,
    TextFieldComponent,
    ThemeToggleComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './overview.component.html',
  styleUrl: './overview.component.scss',
})
export class OverviewComponent {
  private readonly _accountApi = inject(AccountApi);
  private readonly _session = inject(SessionService);
  private readonly _i18n = inject(TranslationService);
  private readonly _toast = inject(ToastService);

  protected readonly user = this._session.user;
  protected readonly quickLinks = QUICK_LINKS;

  protected readonly editingUsername = signal(false);
  protected readonly username = signal('');
  protected readonly usernameError = signal<string | null>(null);
  protected readonly savingUsername = signal(false);

  /** Follows the account, and can be put back if saving a new choice fails. */
  protected readonly language = linkedSignal<string>(() => this.user()?.language ?? this._i18n.language());
  protected readonly savingLanguage = signal(false);

  /** The news from the sites: no switch while the server has none (migration 013 pending). */
  protected readonly hasNewsSwitch = computed(() => typeof this.user()?.news_mail === 'boolean');
  protected readonly newsMail = linkedSignal(() => this.user()?.news_mail ?? true);
  protected readonly savingNewsMail = signal(false);

  /**
   * Nothing to choose between with fewer than two languages known - one on
   * offer, or none yet because the pages are reading the compiled English -
   * and the select is not drawn. The card stays, for the appearance setting it
   * also holds, and is headed after that - so the label the setting has under
   * the select is left out: the word is said once.
   */
  protected readonly canChooseLanguage = computed(() => this._i18n.languages().length >= 2);

  /**
   * The offered languages, each under the name its own speakers give it. An
   * account whose language is no longer offered keeps it until somebody picks
   * another, so it is listed too - last, and by its code, the only name left.
   */
  protected readonly languageOptions = computed<SelectOption[]>(() => {
    const options = this._i18n.languages().map((language) => ({ value: language.code, label: language.native_name }));
    const stored = this.user()?.language;
    if (stored && !options.some((option) => option.value === stored)) {
      options.push({ value: stored, label: stored.toUpperCase() });
    }
    return options;
  });

  protected editUsername(): void {
    this.username.set(this.user()?.username ?? '');
    this.usernameError.set(null);
    this.editingUsername.set(true);
  }

  protected cancelUsername(): void {
    this.editingUsername.set(false);
    this.usernameError.set(null);
  }

  protected saveUsername(): void {
    const username = this.username().trim();
    if (!username) {
      this.usernameError.set(this._i18n.t('common.required'));
      return;
    }
    if (username === this.user()?.username) {
      this.editingUsername.set(false);
      return;
    }

    this.savingUsername.set(true);
    this._accountApi.updateProfile({ username }).subscribe({
      next: (user) => {
        this.savingUsername.set(false);
        this._session.set(user);
        this.editingUsername.set(false);
        this._toast.success(this._i18n.t('overview.usernameSaved'));
      },
      error: (error: ApiError) => {
        this.savingUsername.set(false);
        this.usernameError.set(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  /** Saved the moment it is switched; refused, the switch goes back. */
  protected setNewsMail(on: boolean): void {
    this.savingNewsMail.set(true);
    this._accountApi.updateProfile({ news_mail: on }).subscribe({
      next: (user) => {
        this.savingNewsMail.set(false);
        this._session.set(user);
        this._toast.success(this._i18n.t(on ? 'overview.newsMailOn' : 'overview.newsMailOff'));
      },
      error: (error: ApiError) => {
        this.savingNewsMail.set(false);
        this.newsMail.set(!on);
        this._toast.error(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  /**
   * The page first, the account after. A language whose words cannot be had is
   * not saved: the account would then send mails, and open these pages, in a
   * language this browser has just failed to show. And when the account
   * refuses the change, the page goes back to the language it was shown in and
   * the select to the language the account has. They are not always the same:
   * `?lang=` or a choice on the sign-in page outranks the account for a visit,
   * and an account whose language is no longer offered is shown in another.
   */
  protected async changeLanguage(value: string): Promise<void> {
    const previous = this.user()?.language;
    if (!previous || !value || value === previous || this.savingLanguage()) {
      return;
    }

    // Read before the page switches: this is what it goes back to.
    const shown = this._i18n.language();
    this.savingLanguage.set(true);
    if (!(await this._i18n.choose(value))) {
      this.language.set(previous);
      this.savingLanguage.set(false);
      this._toast.error(this._i18n.t('error.generic'));
      return;
    }

    this._accountApi.updateProfile({ language: value }).subscribe({
      next: (user) => {
        this.savingLanguage.set(false);
        this._session.set(user);
        // Worded only now: in the language just chosen.
        this._toast.success(this._i18n.t('overview.languageSaved'));
      },
      error: (error: ApiError) => {
        void this._languageRefused(error, previous, shown);
      },
    });
  }

  /**
   * Back to the language the page was in a moment ago, which has words: the
   * service still holds the bundle the choice took off the page, so this needs
   * no request - the server may be why the account could not be told. The
   * account's own language may have no words at all - it was switched off -
   * and then nothing could bring the page back to it: it would stay in the
   * language just refused.
   */
  private async _languageRefused(error: ApiError, previous: string, shown: string): Promise<void> {
    await this._i18n.choose(shown);
    this.language.set(previous);
    this.savingLanguage.set(false);
    this._toast.error(errorText(error, (key) => this._i18n.t(key)));
  }
}
