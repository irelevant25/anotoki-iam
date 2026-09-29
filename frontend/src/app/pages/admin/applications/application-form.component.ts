import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../core/api/admin.api';
import { ApiError } from '../../../core/api/api-error';
import { AdminApplication, ApplicationAccess, ApplicationInput, ApplicationMember, ApplicationType } from '../../../core/api/models';
import { AlertComponent } from '../../../ui/alert/alert.component';
import { BadgeComponent } from '../../../ui/badge/badge.component';
import { ButtonComponent } from '../../../ui/button/button.component';
import { CardComponent } from '../../../ui/card/card.component';
import { ConfirmService } from '../../../ui/confirm/confirm.service';
import { CopyButtonComponent } from '../../../ui/copy-button/copy-button.component';
import { DialogComponent } from '../../../ui/dialog/dialog.component';
import { EmptyStateComponent } from '../../../ui/empty-state/empty-state.component';
import { ErrorStateComponent } from '../../../ui/error-state/error-state.component';
import { IconComponent } from '../../../ui/icon/icon.component';
import { PageHeaderComponent } from '../../../ui/page-header/page-header.component';
import { RadioGroupComponent, RadioOption } from '../../../ui/radio-group/radio-group.component';
import { SpinnerComponent } from '../../../ui/spinner/spinner.component';
import { SwitchComponent } from '../../../ui/switch/switch.component';
import { TabItem, TabsComponent } from '../../../ui/tabs/tabs.component';
import { TextFieldComponent } from '../../../ui/text-field/text-field.component';
import { TextareaComponent } from '../../../ui/textarea/textarea.component';
import { ToastService } from '../../../ui/toast/toast.service';
import { adminErrorText } from '../admin-errors';
import { UrlListEditorComponent } from './url-list-editor.component';
import { CLIENT_ID_PATTERN, ROLE_NAME_PATTERN, validOrigin, validRedirectUri } from './url-rules';

interface RoleRow {
  /** A stable key for @for while the name is being typed. */
  key: number;
  name: string;
  description: string;
  members: number;
}

type FieldErrors = Partial<Record<'client_id' | 'name' | 'description' | 'home_url' | 'logo_url' | 'ttl' | 'sort_order' | 'lists' | 'roles', string>>;

const TYPE_OPTIONS: RadioOption[] = [
  { value: 'public', label: 'Public', hint: 'A browser app. PKCE protects the code; there is no secret.' },
  { value: 'confidential', label: 'Confidential', hint: 'A server that can keep a client secret.' },
];

const ACCESS_OPTIONS: RadioOption[] = [
  { value: 'everyone', label: 'Everyone', hint: 'Any account may sign in.' },
  { value: 'members', label: 'Members only', hint: 'Only accounts holding at least one of its roles.' },
];

let roleKey = 0;

/**
 * /admin/applications/new and /admin/applications/:id - everything an
 * application is allowed to ask for.
 *
 * The lists (redirect URIs, logout URIs, origins, roles) are sent whole: the
 * backend replaces each as a set, in one transaction. A role still granted to
 * somebody cannot be dropped - the backend says who holds it, and the page
 * shows that sentence as it is.
 *
 * The IAM's own entry (`system`) cannot be switched off or deleted, so those
 * controls are not drawn for it.
 */
@Component({
  selector: 'app-application-form',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    CopyButtonComponent,
    DialogComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
    RadioGroupComponent,
    SpinnerComponent,
    SwitchComponent,
    TabsComponent,
    TextFieldComponent,
    TextareaComponent,
    UrlListEditorComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './application-form.component.html',
  styleUrl: './application-form.component.scss',
})
export class ApplicationFormComponent {
  /** The `:id` route parameter; absent on /new. */
  readonly id = input<string>();

  private readonly _adminApi = inject(AdminApi);
  private readonly _router = inject(Router);
  private readonly _toast = inject(ToastService);
  private readonly _confirm = inject(ConfirmService);

  protected readonly typeOptions = TYPE_OPTIONS;
  protected readonly accessOptions = ACCESS_OPTIONS;

  protected readonly creating = computed(() => !this.id());
  protected readonly application = signal<AdminApplication | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<string | null>(null);

  protected readonly tab = signal('settings');
  protected readonly tabs = computed<TabItem[]>(() => [
    { id: 'settings', label: 'Settings' },
    { id: 'members', label: 'Members', count: this.members()?.length ?? null },
  ]);
  protected readonly members = signal<ApplicationMember[] | null>(null);
  protected readonly membersError = signal<string | null>(null);

  // ── The form ───────────────────────────────────────────────────────────────
  protected readonly clientId = signal('');
  protected readonly name = signal('');
  protected readonly description = signal('');
  protected readonly homeUrl = signal('');
  protected readonly logoUrl = signal('');
  protected readonly type = signal<string>('public');
  protected readonly enabled = signal(true);
  protected readonly access = signal<string>('everyone');
  protected readonly ttl = signal('600');
  protected readonly redirectUris = signal<string[]>([]);
  protected readonly logoutUris = signal<string[]>([]);
  protected readonly origins = signal<string[]>([]);
  protected readonly roles = signal<RoleRow[]>([]);
  protected readonly showOnLanding = signal(false);
  protected readonly sortOrder = signal('0');

  protected readonly errors = signal<FieldErrors>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly deleting = signal(false);
  protected readonly secretBusy = signal(false);

  /** A client secret, shown this once; `then` is where to go when the dialog closes. */
  protected readonly secret = signal<{ value: string; then: number | null } | null>(null);

  protected readonly system = computed(() => !!this.application()?.system);

  constructor() {
    const destroyRef = inject(DestroyRef);

    toObservable(this.id)
      .pipe(
        tap((id) => {
          this.loadError.set(null);
          this.members.set(null);
          this.tab.set('settings');
          if (!id) {
            this.application.set(null);
            this._reset();
          }
        }),
        switchMap((id) => {
          if (!id) {
            return EMPTY;
          }
          this.loading.set(true);
          return this._adminApi.application(Number(id)).pipe(
            catchError((error: ApiError) => {
              this.loading.set(false);
              this.loadError.set(error.status === 404 ? 'There is no application with this id.' : adminErrorText(error));
              return EMPTY;
            }),
          );
        }),
        takeUntilDestroyed(destroyRef),
      )
      .subscribe((application) => {
        this.loading.set(false);
        this.application.set(application);
        this._fill(application);
      });
  }

  protected reload(): void {
    const id = this.id();
    if (!id) {
      return;
    }
    this.loading.set(true);
    this.loadError.set(null);
    this._adminApi.application(Number(id)).subscribe({
      next: (application) => {
        this.loading.set(false);
        this.application.set(application);
        this._fill(application);
      },
      error: (error: ApiError) => {
        this.loading.set(false);
        this.loadError.set(adminErrorText(error));
      },
    });
  }

  protected showTab(tab: string): void {
    this.tab.set(tab);
    if (tab === 'members' && this.members() === null) {
      this.loadMembers();
    }
  }

  protected loadMembers(): void {
    const id = this.id();
    if (!id) {
      return;
    }
    this.membersError.set(null);
    this._adminApi.members(Number(id)).subscribe({
      next: (members) => this.members.set(members),
      error: (error: ApiError) => this.membersError.set(adminErrorText(error)),
    });
  }

  // ── Roles ──────────────────────────────────────────────────────────────────

  protected addRole(): void {
    this.roles.update((roles) => [...roles, { key: ++roleKey, name: '', description: '', members: 0 }]);
  }

  protected removeRole(key: number): void {
    this.roles.update((roles) => roles.filter((role) => role.key !== key));
  }

  protected setRoleName(key: number, value: string): void {
    // Role names are capitals by rule; typing them in any case is easier.
    this.roles.update((roles) => roles.map((role) => (role.key === key ? { ...role, name: value.toUpperCase().replace(/\s+/g, '_') } : role)));
  }

  protected setRoleDescription(key: number, value: string): void {
    this.roles.update((roles) => roles.map((role) => (role.key === key ? { ...role, description: value } : role)));
  }

  protected roleInvalid(role: RoleRow): boolean {
    return role.name !== '' && !ROLE_NAME_PATTERN.test(role.name);
  }

  // ── Saving ─────────────────────────────────────────────────────────────────

  protected save(): void {
    this.formError.set(null);
    const input = this._validate();
    if (!input) {
      this.formError.set('Some fields need attention - they are marked above.');
      return;
    }

    this.saving.set(true);

    if (this.creating()) {
      this._adminApi.createApplication(input).subscribe({
        next: (created) => {
          this.saving.set(false);
          if (created.client_secret) {
            this.secret.set({ value: created.client_secret, then: created.application.id });
          } else {
            this._toast.success(`${created.application.name} was added.`);
            void this._router.navigate(['/admin/applications', created.application.id]);
          }
        },
        error: (error: ApiError) => this._refused(error),
      });
      return;
    }

    this._adminApi.updateApplication(Number(this.id()), input).subscribe({
      next: (application) => {
        this.saving.set(false);
        this.application.set(application);
        this._fill(application);
        this._toast.success('Saved.');
      },
      error: (error: ApiError) => this._refused(error),
    });
  }

  protected async newSecret(): Promise<void> {
    const application = this.application();
    if (!application) {
      return;
    }
    const sure = await this._confirm.ask({
      title: application.has_secret ? 'Replace the client secret?' : 'Generate a client secret?',
      message: application.has_secret
        ? 'The current secret stops working at once. The application has to be given the new one before it can sign anybody in again.'
        : 'The secret is shown once. Put it in the application’s configuration straight away.',
      confirmLabel: 'Generate secret',
      tone: application.has_secret ? 'danger' : 'primary',
    });
    if (!sure) {
      return;
    }

    this.secretBusy.set(true);
    this._adminApi.newSecret(application.id).subscribe({
      next: (response) => {
        this.secretBusy.set(false);
        this.secret.set({ value: response.client_secret, then: null });
        this.application.set({ ...application, has_secret: true });
      },
      error: (error: ApiError) => {
        this.secretBusy.set(false);
        this._toast.error(adminErrorText(error));
      },
    });
  }

  protected secretClosed(): void {
    const then = this.secret()?.then ?? null;
    this.secret.set(null);
    if (then !== null) {
      this._toast.success('The application was added.');
      void this._router.navigate(['/admin/applications', then]);
    }
  }

  protected async remove(): Promise<void> {
    const application = this.application();
    if (!application || application.system) {
      return;
    }
    const sure = await this._confirm.ask({
      title: `Delete ${application.name}?`,
      message: 'Its addresses, its roles and every grant of them go with it, and every token it issued stops working at the next refresh. This cannot be undone.',
      confirmLabel: 'Delete application',
      tone: 'danger',
    });
    if (!sure) {
      return;
    }

    this.deleting.set(true);
    this._adminApi.deleteApplication(application.id).subscribe({
      next: () => {
        this.deleting.set(false);
        this._toast.success(`${application.name} was deleted.`);
        void this._router.navigate(['/admin/applications']);
      },
      error: (error: ApiError) => {
        this.deleting.set(false);
        this._toast.error(adminErrorText(error));
      },
    });
  }

  // ── The machinery ──────────────────────────────────────────────────────────

  private _refused(error: ApiError): void {
    this.saving.set(false);
    const text = adminErrorText(error);
    if (error.code === 'client_id_taken' || error.code === 'invalid_client_id') {
      this.errors.update((errors) => ({ ...errors, client_id: text }));
    }
    this.formError.set(text);
  }

  private _reset(): void {
    this.clientId.set('');
    this.name.set('');
    this.description.set('');
    this.homeUrl.set('');
    this.logoUrl.set('');
    this.type.set('public');
    this.enabled.set(true);
    this.access.set('everyone');
    this.ttl.set('600');
    this.redirectUris.set([]);
    this.logoutUris.set([]);
    this.origins.set([]);
    this.roles.set([]);
    this.showOnLanding.set(false);
    this.sortOrder.set('0');
    this.errors.set({});
    this.formError.set(null);
  }

  private _fill(application: AdminApplication): void {
    this.clientId.set(application.client_id);
    this.name.set(application.name);
    this.description.set(application.description ?? '');
    this.homeUrl.set(application.home_url ?? '');
    this.logoUrl.set(application.logo_url ?? '');
    this.type.set(application.type);
    this.enabled.set(application.enabled);
    this.access.set(application.access);
    this.ttl.set(String(application.access_token_ttl));
    this.redirectUris.set([...application.redirect_uris]);
    this.logoutUris.set([...application.post_logout_redirect_uris]);
    this.origins.set([...application.web_origins]);
    this.roles.set(application.roles.map((role) => ({ key: ++roleKey, name: role.name, description: role.description ?? '', members: role.members })));
    this.showOnLanding.set(application.show_on_landing);
    this.sortOrder.set(String(application.sort_order));
    this.errors.set({});
    this.formError.set(null);
  }

  /** The body to send, or null with the problems marked. */
  private _validate(): ApplicationInput | null {
    const errors: FieldErrors = {};
    const clientId = this.clientId().trim().toLowerCase();
    const name = this.name().trim();
    const ttl = Number(this.ttl());
    const sortOrder = Number(this.sortOrder());
    const clean = (list: string[]) => [...new Set(list.map((item) => item.trim()).filter(Boolean))];
    const redirectUris = clean(this.redirectUris());
    const logoutUris = clean(this.logoutUris());
    const origins = clean(this.origins()).map((origin) => origin.replace(/\/+$/, ''));

    if (this.creating() && !CLIENT_ID_PATTERN.test(clientId)) {
      errors.client_id = '2-64 lowercase letters, digits and dashes, starting with a letter or digit.';
    }
    if (!name) {
      errors.name = 'A name is needed.';
    } else if (name.length > 100) {
      errors.name = 'At most 100 characters.';
    }
    if (this.description().length > 2000) {
      errors.description = 'At most 2000 characters.';
    }
    if (this.homeUrl().trim() && !validRedirectUri(this.homeUrl().trim())) {
      errors.home_url = 'An absolute http(s) address.';
    }
    if (this.logoUrl().trim() && !validRedirectUri(this.logoUrl().trim())) {
      errors.logo_url = 'An absolute http(s) address.';
    }
    if (!Number.isInteger(ttl) || ttl < 60 || ttl > 3600) {
      errors.ttl = 'Whole seconds, from 60 to 3600.';
    }
    if (!Number.isInteger(sortOrder)) {
      errors.sort_order = 'A whole number.';
    }
    if (redirectUris.some((uri) => !validRedirectUri(uri)) || logoutUris.some((uri) => !validRedirectUri(uri)) || origins.some((origin) => !validOrigin(origin))) {
      errors.lists = 'Fix the marked addresses.';
    }

    const roles = this.roles()
      .map((role) => ({ name: role.name.trim(), description: role.description.trim() || null }))
      .filter((role) => role.name !== '');
    const names = roles.map((role) => role.name);
    if (roles.some((role) => !ROLE_NAME_PATTERN.test(role.name))) {
      errors.roles = 'Role names are capitals, digits and underscores, starting with a capital.';
    } else if (new Set(names).size !== names.length) {
      errors.roles = 'Each role name can be used once.';
    } else if (this.system() && !names.includes('ADMIN')) {
      errors.roles = 'The IAM must keep its ADMIN role - it is what makes somebody an administrator here.';
    }

    this.errors.set(errors);
    if (Object.keys(errors).length > 0) {
      return null;
    }

    const input: ApplicationInput = {
      name,
      description: this.description().trim() || null,
      home_url: this.homeUrl().trim() || null,
      logo_url: this.logoUrl().trim() || null,
      type: this.type() as ApplicationType,
      access: this.access() as ApplicationAccess,
      access_token_ttl: ttl,
      redirect_uris: redirectUris,
      post_logout_redirect_uris: logoutUris,
      web_origins: origins,
      roles,
      show_on_landing: this.showOnLanding(),
      sort_order: sortOrder,
    };
    if (this.creating()) {
      input.client_id = clientId;
    }
    if (!this.system()) {
      input.enabled = this.enabled();
    }
    return input;
  }
}
