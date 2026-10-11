import { Routes } from '@angular/router';
import { AdminLayoutComponent } from './admin-layout.component';
import { unsavedChangesGuard } from './localization/unsaved-changes.guard';

/** /admin - behind the session guard; the layout itself shuts out anybody who is not an IAM admin. */
export const ADMIN_ROUTES: Routes = [
  {
    path: '',
    component: AdminLayoutComponent,
    children: [
      { path: '', title: 'Dashboard', loadComponent: () => import('./dashboard/dashboard.component').then((m) => m.DashboardComponent) },
      { path: 'users', title: 'Users', loadComponent: () => import('./users/users-list.component').then((m) => m.UsersListComponent) },
      { path: 'users/:id', title: 'User', loadComponent: () => import('./users/user-detail.component').then((m) => m.UserDetailComponent) },
      { path: 'approvals', title: 'Approvals', loadComponent: () => import('./approvals/approvals.component').then((m) => m.ApprovalsComponent) },
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
      {
        path: 'announcements',
        title: 'Announcements',
        loadComponent: () => import('./announcements/announcements.component').then((m) => m.AnnouncementsComponent),
      },
      // A draft is held only by its page, and an announcement is sent only while its page is open: leaving either asks first.
      {
        path: 'announcements/new',
        title: 'New announcement',
        canDeactivate: [unsavedChangesGuard],
        loadComponent: () => import('./announcements/announcement-compose.component').then((m) => m.AnnouncementComposeComponent),
      },
      {
        path: 'announcements/:id',
        title: 'Announcement',
        canDeactivate: [unsavedChangesGuard],
        loadComponent: () => import('./announcements/announcement-detail.component').then((m) => m.AnnouncementDetailComponent),
      },
      { path: 'emails', title: 'Email log', loadComponent: () => import('./emails/emails.component').then((m) => m.EmailsComponent) },
      { path: 'audit', title: 'Audit log', loadComponent: () => import('./audit/audit.component').then((m) => m.AuditComponent) },
      { path: 'settings', title: 'Settings', loadComponent: () => import('./settings/settings.component').then((m) => m.SettingsComponent) },
      // The two pages hold what is typed until it is saved: leaving one with something unsaved asks first.
      {
        path: 'translations',
        title: 'Translations',
        canDeactivate: [unsavedChangesGuard],
        loadComponent: () => import('./localization/translations/translations.component').then((m) => m.TranslationsComponent),
      },
      {
        path: 'languages',
        title: 'Languages',
        canDeactivate: [unsavedChangesGuard],
        loadComponent: () => import('./localization/languages/languages.component').then((m) => m.LanguagesComponent),
      },
      { path: 'keys', title: 'Signing keys', loadComponent: () => import('./keys/keys.component').then((m) => m.KeysComponent) },
      // The anotoki family's Migrations page (@anotoki/lib), as every anotoki site has it.
      { path: 'migrations', title: 'Migrations', loadComponent: () => import('@anotoki/lib/migrations/page').then((m) => m.MigrationsPageComponent) },
    ],
  },
];
