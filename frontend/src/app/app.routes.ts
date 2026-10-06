import { Routes } from '@angular/router';
import { sessionGuard } from './core/auth/guards';

/**
 * Three areas, each loaded only when it is visited:
 *
 *   the sign-in pages   no session needed; bilingual; the night-sky layout
 *   /account            the signed-in account; bilingual; the app shell
 *   /admin              IAM administrators; English; the app shell
 *
 * The sign-in layout comes last because its not-found child (`**`) would
 * otherwise swallow /account and /admin before they were tried. A URL under
 * either area that none of its pages match falls through to that same page.
 *
 * Titles are translation keys on the translated pages and plain English in
 * the admin panel; IamTitleStrategy takes either.
 */
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'account' },
  {
    path: 'account',
    canActivate: [sessionGuard],
    loadChildren: () => import('./pages/account/account.routes').then((m) => m.ACCOUNT_ROUTES),
  },
  {
    path: 'admin',
    canActivate: [sessionGuard],
    loadChildren: () => import('./pages/admin/admin.routes').then((m) => m.ADMIN_ROUTES),
  },
  {
    path: '',
    loadComponent: () => import('./layouts/auth-layout/auth-layout.component').then((m) => m.AuthLayoutComponent),
    children: [
      { path: 'login', title: 'login.pageTitle', loadComponent: () => import('./pages/auth/login/login.component').then((m) => m.LoginComponent) },
      { path: 'register', title: 'register.pageTitle', loadComponent: () => import('./pages/auth/register/register.component').then((m) => m.RegisterComponent) },
      {
        path: 'confirm-email',
        title: 'confirm.pageTitle',
        loadComponent: () => import('./pages/auth/confirm-email/confirm-email.component').then((m) => m.ConfirmEmailComponent),
      },
      {
        path: 'forgot-password',
        title: 'forgot.pageTitle',
        loadComponent: () => import('./pages/auth/forgot-password/forgot-password.component').then((m) => m.ForgotPasswordComponent),
      },
      {
        path: 'reset-password',
        title: 'reset.pageTitle',
        loadComponent: () => import('./pages/auth/reset-password/reset-password.component').then((m) => m.ResetPasswordComponent),
      },
      { path: 'logout', title: 'logout.pageTitle', loadComponent: () => import('./pages/auth/logout/logout.component').then((m) => m.LogoutComponent) },
      {
        path: 'unsubscribe',
        title: 'unsubscribe.pageTitle',
        loadComponent: () => import('./pages/auth/unsubscribe/unsubscribe.component').then((m) => m.UnsubscribeComponent),
      },
      { path: 'error', title: 'errorPage.pageTitle', loadComponent: () => import('./pages/auth/error/error-page.component').then((m) => m.ErrorPageComponent) },
      {
        path: 'not-set-up',
        title: 'notSetUp.pageTitle',
        loadComponent: () => import('./pages/auth/not-set-up/not-set-up.component').then((m) => m.NotSetUpComponent),
      },
      { path: '**', title: 'notFound.pageTitle', loadComponent: () => import('./pages/auth/not-found/not-found.component').then((m) => m.NotFoundComponent) },
    ],
  },
];
