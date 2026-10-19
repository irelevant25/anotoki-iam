import { Routes } from '@angular/router';
import { sessionGuard } from './core/auth/guards';
import { notSetUpTitle } from './pages/auth/not-set-up/not-set-up-title';

/**
 * Four areas, each loaded only when it is visited - each with the anotoki
 * family's footer (@anotoki/lib/footer) under its pages:
 *
 *   the sign-in pages   no session needed; bilingual; the night-sky layout
 *   /account            the signed-in account; bilingual; the app shell
 *   /admin              IAM administrators; English; the app shell
 *   the legal pages     anybody's; bilingual; the app shell - /terms,
 *                       /privacy (the one every anotoki site links to for the
 *                       account) and /contact, the family's pages
 *                       (@anotoki/lib/legal/pages), with the IAM's facts
 *                       (core/legal/legal-facts.ts)
 *
 * - and /not-set-up, the family's status page, which is a page of its own:
 * on no layout, so with no bar, no controls and no footer round it (the
 * owner's rule of 2026-10-18; the IAM has no gate to draw it - it never
 * closes while an update waits).
 *
 * The sign-in layout comes last because its not-found child (`**`) would
 * otherwise swallow the others before they were tried. A URL under /account
 * or /admin that none of its pages match falls through to that same page.
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
    path: 'not-set-up',
    title: notSetUpTitle,
    loadComponent: () => import('./pages/auth/not-set-up/not-set-up.component').then((m) => m.NotSetUpComponent),
  },
  {
    path: '',
    loadComponent: () => import('./layouts/legal-layout/legal-layout.component').then((m) => m.LegalLayoutComponent),
    children: [
      { path: 'terms', title: 'legal.termsPageTitle', loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.TermsPageComponent) },
      { path: 'privacy', title: 'legal.privacyPageTitle', loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.PrivacyPageComponent) },
      { path: 'contact', title: 'legal.contactPageTitle', loadComponent: () => import('@anotoki/lib/legal/pages').then((m) => m.ContactPageComponent) },
      // The family's About and Support pages (anotoki-lib 0.8.0), the same on every anotoki site; titled by the library's words.
      { path: 'about', title: 'anotoki.about.title', loadComponent: () => import('@anotoki/lib/legal/about').then((m) => m.AboutPageComponent) },
      { path: 'support', title: 'anotoki.support.title', loadComponent: () => import('@anotoki/lib/legal/support').then((m) => m.SupportPageComponent) },
    ],
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
      { path: '**', title: 'notFound.pageTitle', loadComponent: () => import('./pages/auth/not-found/not-found.component').then((m) => m.NotFoundComponent) },
    ],
  },
];
