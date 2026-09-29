import { ChangeDetectionStrategy, Component, ElementRef, computed, inject, input, signal, viewChild } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { ApiError } from '../../core/api/api-error';
import { LoginApi } from '../../core/api/login.api';
import { SessionService } from '../../core/auth/session.service';
import { errorText } from '../../core/i18n/error-text';
import { TPipe } from '../../core/i18n/t.pipe';
import { TranslationService } from '../../core/i18n/translation.service';
import { BrandComponent } from '../../ui/brand/brand.component';
import { IconComponent, IconName } from '../../ui/icon/icon.component';
import { SpinnerComponent } from '../../ui/spinner/spinner.component';
import { ThemeToggleComponent } from '../../ui/theme-toggle/theme-toggle.component';
import { ToastService } from '../../ui/toast/toast.service';

export interface NavItem {
  link: string;
  label: string;
  icon: IconName;
  /** Active only on this exact path - for the area's own home, which every other path starts with. */
  exact?: boolean;
}

/**
 * The frame of the account and admin areas: a top bar (the mark, the theme,
 * the signed-in person and their menu) and a side navigation that becomes a
 * scrolling strip of links under the top bar on a phone.
 *
 * The labels arrive translated (the account area) or in English (the admin
 * console, where the `t` pipe answers in English too), so the shell itself
 * never decides a language.
 */
@Component({
  selector: 'app-shell',
  imports: [RouterLink, RouterLinkActive, TPipe, BrandComponent, IconComponent, SpinnerComponent, ThemeToggleComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss',
  host: {
    '(document:click)': 'onDocumentClick($event)',
    '(document:keydown.escape)': 'closeMenu(true)',
  },
})
export class ShellComponent {
  readonly area = input.required<'account' | 'admin'>();
  readonly nav = input.required<NavItem[]>();

  private readonly _session = inject(SessionService);
  private readonly _loginApi = inject(LoginApi);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _i18n = inject(TranslationService);
  private readonly _host = inject<ElementRef<HTMLElement>>(ElementRef);

  protected readonly user = this._session.user;
  protected readonly isAdmin = this._session.isAdmin;
  protected readonly menuOpen = signal(false);
  protected readonly signingOut = signal(false);

  protected readonly initial = computed(() => (this.user()?.username.trim()[0] ?? '?').toUpperCase());
  protected readonly home = computed(() => (this.area() === 'admin' ? '/admin' : '/account'));

  private readonly _menuButton = viewChild<ElementRef<HTMLButtonElement>>('menuButton');
  private readonly _menu = viewChild<ElementRef<HTMLElement>>('menu');

  protected toggleMenu(): void {
    if (this.menuOpen()) {
      this.closeMenu(true);
    } else {
      this.menuOpen.set(true);
      // Into the menu once it is drawn, as a menu button is expected to do.
      queueMicrotask(() => this._menuItems()[0]?.focus());
    }
  }

  closeMenu(returnFocus: boolean): void {
    if (!this.menuOpen()) {
      return;
    }
    this.menuOpen.set(false);
    if (returnFocus) {
      this._menuButton()?.nativeElement.focus();
    }
  }

  protected onDocumentClick(event: MouseEvent): void {
    const menuRoot = this._host.nativeElement.querySelector('.user-menu');
    if (this.menuOpen() && menuRoot && !menuRoot.contains(event.target as Node)) {
      this.closeMenu(false);
    }
  }

  protected onMenuKeydown(event: KeyboardEvent): void {
    const items = this._menuItems();
    const index = items.indexOf(document.activeElement as HTMLElement);
    let next = -1;

    if (event.key === 'ArrowDown') {
      next = (index + 1) % items.length;
    } else if (event.key === 'ArrowUp') {
      next = (index - 1 + items.length) % items.length;
    } else if (event.key === 'Home') {
      next = 0;
    } else if (event.key === 'End') {
      next = items.length - 1;
    } else if (event.key === 'Tab') {
      this.closeMenu(false);
      return;
    }

    if (next >= 0) {
      event.preventDefault();
      items[next]?.focus();
    }
  }

  protected signOut(): void {
    this.signingOut.set(true);
    this._loginApi.logout().subscribe({
      next: () => {
        this.signingOut.set(false);
        this.menuOpen.set(false);
        this._session.clear();
        void this._router.navigate(['/logout'], { state: { signedOut: true } });
      },
      error: (error: ApiError) => {
        this.signingOut.set(false);
        this._toast.error(error.status === 0 ? errorText(error, (key) => this._i18n.t(key)) : this._i18n.t('shell.signOutFailed'));
      },
    });
  }

  private _menuItems(): HTMLElement[] {
    const menu = this._menu()?.nativeElement;
    return menu ? Array.from(menu.querySelectorAll<HTMLElement>('[role="menuitem"]')) : [];
  }
}
