import { Routes } from '@angular/router';
import { AdminLayoutComponent } from './admin-layout.component';

/** /admin - behind the session guard; the layout itself shuts out anybody who is not an IAM admin. */
export const ADMIN_ROUTES: Routes = [
  {
    path: '',
    component: AdminLayoutComponent,
    children: [
      { path: '', title: 'Dashboard', loadComponent: () => import('./dashboard/dashboard.component').then((m) => m.DashboardComponent) },
      { path: 'users', title: 'Users', loadComponent: () => import('./users/users-list.component').then((m) => m.UsersListComponent) },
      { path: 'users/:id', title: 'User', loadComponent: () => import('./users/user-detail.component').then((m) => m.UserDetailComponent) },
      { path: 'sessions', title: 'Sessions', loadComponent: () => import('./sessions/admin-sessions.component').then((m) => m.AdminSessionsComponent) },
      {
        path: 'applications',
        title: 'Applications',
        loadComponent: () => import('./applications/applications-list.component').then((m) => m.ApplicationsListComponent),
      },
      {
        path: 'applications/new',
        title: 'New application',
        loadComponent: () => import('./applications/application-form.component').then((m) => m.ApplicationFormComponent),
      },
      {
        path: 'applications/:id',
        title: 'Application',
        loadComponent: () => import('./applications/application-form.component').then((m) => m.ApplicationFormComponent),
      },
      { path: 'emails', title: 'Email log', loadComponent: () => import('./emails/emails.component').then((m) => m.EmailsComponent) },
      { path: 'audit', title: 'Audit log', loadComponent: () => import('./audit/audit.component').then((m) => m.AuditComponent) },
      { path: 'settings', title: 'Settings', loadComponent: () => import('./settings/settings.component').then((m) => m.SettingsComponent) },
      { path: 'keys', title: 'Signing keys', loadComponent: () => import('./keys/keys.component').then((m) => m.KeysComponent) },
      { path: 'migrations', title: 'Migrations', loadComponent: () => import('./migrations/migrations.component').then((m) => m.MigrationsComponent) },
    ],
  },
];
