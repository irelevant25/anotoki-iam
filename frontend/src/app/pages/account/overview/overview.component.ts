import { ChangeDetectionStrategy, Component, computed, inject, linkedSignal, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { ThemeToggleComponent } from '@anotoki/lib/shell';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { SelectComponent, SelectOption, SwitchComponent, TextFieldComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { ToastService } from '@anotoki/lib/ui/toast';
import { AccountApi } from '../../../core/api/account.api';
import { ApiError } from '../../../core/api/api-error';
import { DateFormatChoice, TimeFormatChoice } from '../../../core/api/models';
import { SessionService } from '../../../core/auth/session.service';
import { AppDatePipe } from '../../../core/i18n/app-date.pipe';
import { DATE_FORMATS, TIME_FORMATS, formatClock, formatDatePattern } from '../../../core/i18n/date-patterns';
import { TranslationKey } from '../../../core/i18n/en';
import { errorText } from '../../../core/i18n/error-text';
import { TPipe } from '../../../core/i18n/t.pipe';
import { TranslationService } from '../../../core/i18n/translation.service';
import { IconName } from '../../../core/kit/icons';

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
 *
 * The theme, the dates and the clock are the account's too (migration 015):
 * every anotoki site where the person uses the account follows them. The
 * theme toggle saves through the theme service, as every toggle on these
 * pages does; the two formats are saved here, the moment they are chosen.
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

  /** The dates and the clock: not offered while the server keeps none (migration 015 pending). '' is "Automatic". */
  protected readonly hasFormats = computed(() => !!this.user()?.preferences);
  protected readonly dateFormat = linkedSignal<string>(() => this.user()?.preferences?.date_format ?? '');
  protected readonly timeFormat = linkedSignal<string>(() => this.user()?.preferences?.time_format ?? '');
  protected readonly savingFormat = signal(false);

  /**
   * Each order named by an example rather than by a pattern - "31.12.2026"
   * says what is being chosen, "d.M.yyyy" only to somebody who knows already.
   * The last day of the year, so the day can never be read as the month.
   * "Automatic" shows what the page's language makes of the same day.
   */
  protected readonly dateFormatOptions = computed<SelectOption[]>(() => {
    const locale = this._i18n.locale();
    const sample = sampleMoment();
    const automatic = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric' }).format(sample);
    return [{ value: '', label: `${this._i18n.t('overview.formatAutomatic')} (${automatic})` }, ...DATE_FORMATS.map((format) => ({ value: format, label: formatDatePattern(sample, format) }))];
  });

  protected readonly timeFormatOptions = computed<SelectOption[]>(() => {
    const locale = this._i18n.locale();
    const sample = sampleMoment();
    const automatic = new Intl.DateTimeFormat(locale, { hour: '2-digit', minute: '2-digit' }).format(sample);
    const label: Record<TimeFormatChoice, string> = { '24': this._i18n.t('overview.clock24'), '12': this._i18n.t('overview.clock12') };
    return [
      { value: '', label: `${this._i18n.t('overview.formatAutomatic')} (${automatic})` },
      ...TIME_FORMATS.map((clock) => ({ value: clock, label: `${label[clock]} (${formatClock(sample, locale, clock)})` })),
    ];
  });

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
    this._session.setFrom(this._accountApi.updateProfile({ username })).subscribe({
      next: () => {
        this.savingUsername.set(false);
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
    this._session.setFrom(this._accountApi.updateProfile({ news_mail: on })).subscribe({
      next: () => {
        this.savingNewsMail.set(false);
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
   * Saved the moment it is chosen, one at a time - both selects wait while a
   * save is on its way. Refused, the select goes back to what the account has.
   */
  protected changeFormat(field: 'date_format' | 'time_format', value: string): void {
    const preferences = this.user()?.preferences;
    if (!preferences || (preferences[field] ?? '') === value || this.savingFormat()) {
      return;
    }

    this.savingFormat.set(true);
    const body = field === 'date_format' ? { date_format: (value || null) as DateFormatChoice | null } : { time_format: (value || null) as TimeFormatChoice | null };
    this._session.setFrom(this._accountApi.updateProfile(body)).subscribe({
      next: () => {
        this.savingFormat.set(false);
        this._toast.success(this._i18n.t('overview.preferencesSaved'));
      },
      error: (error: ApiError) => {
        this.savingFormat.set(false);
        this.dateFormat.set(this.user()?.preferences?.date_format ?? '');
        this.timeFormat.set(this.user()?.preferences?.time_format ?? '');
        this._toast.error(errorText(error, (key) => this._i18n.t(key)));
      },
    });
  }

  /**
   * The page first, the account after - the family's translations do both
   * (`setLanguage(code, { revertible: true })`), in the same one-at-a-time
   * line as the top bar's choices, so the two cannot leave the account on a
   * language the page no longer shows. A language whose words cannot be had is
   * not saved: the account would then send mails, and open these pages, in a
   * language this browser has just failed to show. And when the account
   * refuses the change, the page goes back to the language it was shown in -
   * from memory, with no request: the server may be why the account could not
   * be told - and the select to the language the account has. They are not
   * always the same: `?lang=` or a choice on the sign-in page outranks the
   * account for a visit, and an account whose language is no longer offered is
   * shown in another. Either way nothing changed, and the page says so.
   */
  protected async changeLanguage(value: string): Promise<void> {
    const previous = this.user()?.language;
    if (!previous || !value || value === previous || this.savingLanguage()) {
      return;
    }

    this.savingLanguage.set(true);
    const saved = await this._i18n.setLanguage(value, { revertible: true });
    this.savingLanguage.set(false);
    if (saved) {
      // Worded only now: in the language just chosen.
      this._toast.success(this._i18n.t('overview.languageSaved'));
      return;
    }
    this.language.set(this.user()?.language ?? previous);
    this._toast.error(this._i18n.t('error.generic'));
  }
}

/** 31 December of this year, half past two in the afternoon: no order can make the day look like a month, and the hour shows which clock. */
function sampleMoment(): Date {
  return new Date(new Date().getFullYear(), 11, 31, 14, 30);
}
