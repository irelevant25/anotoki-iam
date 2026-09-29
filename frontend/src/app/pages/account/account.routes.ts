import { Routes } from '@angular/router';
import { AccountLayoutComponent } from './account-layout.component';

/** /account - everything here runs behind the session guard in app.routes.ts. */
export const ACCOUNT_ROUTES: Routes = [
  {
    path: '',
    component: AccountLayoutComponent,
    children: [
      { path: '', title: 'overview.pageTitle', loadComponent: () => import('./overview/overview.component').then((m) => m.OverviewComponent) },
      { path: 'security', title: 'security.pageTitle', loadComponent: () => import('./security/security.component').then((m) => m.SecurityComponent) },
      { path: 'sessions', title: 'sessions.pageTitle', loadComponent: () => import('./sessions/sessions.component').then((m) => m.SessionsComponent) },
      {
        path: 'applications',
        title: 'apps.pageTitle',
        loadComponent: () => import('./applications/applications.component').then((m) => m.ApplicationsComponent),
      },
      { path: 'tokens', title: 'tokens.pageTitle', loadComponent: () => import('./tokens/tokens.component').then((m) => m.TokensComponent) },
    ],
  },
];
