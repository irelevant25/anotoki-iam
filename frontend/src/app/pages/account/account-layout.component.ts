import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TranslationService } from '../../core/i18n/translation.service';
import { NavItem, ShellComponent } from '../../layouts/shell/shell.component';

/** The account area: the shell with the account's own navigation. */
@Component({
  selector: 'app-account-layout',
  imports: [RouterOutlet, ShellComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './account-layout.component.html',
  styleUrl: './account-layout.component.scss',
})
export class AccountLayoutComponent {
  private readonly _i18n = inject(TranslationService);

  protected readonly nav = computed<NavItem[]>(() => [
    { link: '/account', label: this._i18n.t('account.nav.overview'), icon: 'user', exact: true },
    { link: '/account/security', label: this._i18n.t('account.nav.security'), icon: 'shield' },
    { link: '/account/sessions', label: this._i18n.t('account.nav.sessions'), icon: 'laptop' },
    { link: '/account/applications', label: this._i18n.t('account.nav.applications'), icon: 'grid' },
    { link: '/account/tokens', label: this._i18n.t('account.nav.tokens'), icon: 'terminal' },
  ]);
}
