import { ChangeDetectionStrategy, Component, computed, inject, linkedSignal, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { LANGUAGES, Language } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService, isLanguage } from '../../../core/i18n/translation.service';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { IconComponent, IconName } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { SelectComponent, SelectOption } from '../../../ui/select/select.component';
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
 * chosen, and the page switches to it straight away - waiting for the server
 * to repaint in the language somebody just picked would feel broken.
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

  protected readonly languageOptions = computed<SelectOption[]>(() => LANGUAGES.map((language) => ({ value: language, label: this._i18n.t(language === 'en' ? 'lang.en' : 'lang.sk') })));

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

  protected changeLanguage(value: string): void {
    const previous = this.user()?.language;
    if (!isLanguage(value) || value === previous) {
      return;
    }

    const language: Language = value;
    this._i18n.choose(language);
    this.savingLanguage.set(true);
    this._accountApi.updateProfile({ language }).subscribe({
      next: (user) => {
        this.savingLanguage.set(false);
        this._session.set(user);
        this._toast.success(this._i18n.t('overview.languageSaved'));
      },
      error: (error: ApiError) => {
        this.savingLanguage.set(false);
        if (previous) {
          this._i18n.choose(previous);
          this.language.set(previous);
        }
        this._toast.error(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }
}
