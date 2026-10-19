import { inject, provideEnvironmentInitializer } from '@angular/core';
import { Routes } from '@angular/router';
import { anotokiUnsavedChangesGuard } from '@anotoki/lib/translations';
import { useAdminToolsPanel } from '../../core/admin/admin-tools.config';
import { SessionService } from '../../core/auth/session.service';
import { useAdminPages } from '../../core/i18n/translations.config';
import { AdminLayoutComponent } from './admin-layout.component';
import { adminToolsPanel } from './admin-tools';
import { localizationPages } from './localization';

/** /admin - behind the session guard; the layout itself shuts out anybody who is not an IAM admin. */
export const ADMIN_ROUTES: Routes = [
  {
    path: '',
    component: AdminLayoutComponent,
    // The Translations and Languages pages as the IAM words them, handed to the family's translations as the
    // panel loads - before either page can be made - and kept out of the first load (core/i18n/translations.config.ts);
    // the same for the family's admin tools - the Error log, Audit log, Feedback and Backups pages
    // (core/admin/admin-tools.config.ts).
    providers: [provideEnvironmentInitializer(() => useAdminPages(localizationPages(inject(SessionService)))), provideEnvironmentInitializer(() => useAdminToolsPanel(adminToolsPanel()))],
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
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('./announcements/announcement-compose.component').then((m) => m.AnnouncementComposeComponent),
      },
      {
        path: 'announcements/:id',
        title: 'Announcement',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('./announcements/announcement-detail.component').then((m) => m.AnnouncementDetailComponent),
      },
      { path: 'emails', title: 'Email log', loadComponent: () => import('./emails/emails.component').then((m) => m.EmailsComponent) },
      // The anotoki family's admin tools (@anotoki/lib/admin/*), each a page of its own (core/admin/admin-tools.config.ts).
      // Their filters are the address's query parameters: /admin/audit?target_type=user&target_id=42 is everything about one account.
      { path: 'audit', title: 'Audit log', loadComponent: () => import('@anotoki/lib/admin/audit').then((m) => m.AuditLogPageComponent) },
      { path: 'errors', title: 'Error log', loadComponent: () => import('@anotoki/lib/admin/errors').then((m) => m.ErrorLogPageComponent) },
      // What people sent through the family's feedback form (the owner's decision of 2026-10-19): by status, kind and words,
      // a message opened in a dialog - its words as text -, marked or deleted, each change audited.
      { path: 'feedback', title: 'Feedback', loadComponent: () => import('@anotoki/lib/admin/feedback').then((m) => m.FeedbackPageComponent) },
      { path: 'settings', title: 'Settings', loadComponent: () => import('./settings/settings.component').then((m) => m.SettingsComponent) },
      // The anotoki family's Translations and Languages pages (@anotoki/lib, as the IAM words them:
      // ./localization.ts). They hold what is typed until it is saved: leaving one with something unsaved asks first.
      {
        path: 'translations',
        title: 'Translations',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('@anotoki/lib/translations/admin').then((m) => m.TranslationsPageComponent),
      },
      {
        path: 'languages',
        title: 'Languages',
        canDeactivate: [anotokiUnsavedChangesGuard],
        loadComponent: () => import('@anotoki/lib/translations/admin').then((m) => m.LanguagesPageComponent),
      },
      { path: 'keys', title: 'Signing keys', loadComponent: () => import('./keys/keys.component').then((m) => m.KeysComponent) },
      // Encrypted with a key from the master key, restoring off; the proof is the IAM's own (docs/decisions.md, 2026-10-11).
      { path: 'backups', title: 'Backups', loadComponent: () => import('@anotoki/lib/admin/backups').then((m) => m.BackupsPageComponent) },
      // The anotoki family's Migrations page (@anotoki/lib), as every anotoki site has it.
      { path: 'migrations', title: 'Migrations', loadComponent: () => import('@anotoki/lib/migrations/page').then((m) => m.MigrationsPageComponent) },
    ],
  },
];
