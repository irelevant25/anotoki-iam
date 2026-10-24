import { ChangeDetectionStrategy, Component, DestroyRef, computed, inject, input, signal } from '@angular/core';
import { takeUntilDestroyed, toObservable } from '@angular/core/rxjs-interop';
import { Router, RouterLink } from '@angular/router';
import { AlertComponent } from '@anotoki/lib/ui/alert';
import { BadgeComponent } from '@anotoki/lib/ui/badge';
import { ButtonComponent } from '@anotoki/lib/ui/button';
import { CardComponent } from '@anotoki/lib/ui/card';
import { ConfirmService } from '@anotoki/lib/ui/dialog';
import { EmptyStateComponent } from '@anotoki/lib/ui/empty-state';
import { ErrorStateComponent } from '@anotoki/lib/ui/error-state';
import { CheckboxComponent, SwitchComponent, TextFieldComponent, TextareaComponent } from '@anotoki/lib/ui/forms';
import { IconComponent } from '@anotoki/lib/ui/icon';
import { PageHeaderComponent } from '@anotoki/lib/ui/page-header';
import { SpinnerComponent } from '@anotoki/lib/ui/spinner';
import { TabItem, TabsComponent } from '@anotoki/lib/ui/tabs';
import { ToastService } from '@anotoki/lib/ui/toast';
import { EMPTY, catchError, switchMap, tap } from 'rxjs';
import { AdminApi } from '../../../api/admin.api';
import { ApiError } from '../../../api/api-error';
import { AdminApplication, ApplicationInput, ApplicationMembersResponse } from '../../../api/models';
import { adminErrorText } from '../shared/admin-errors';
import { UrlListEditorComponent } from './url-list-editor.component';
import { CLIENT_ID_PATTERN, validOrigin, validRedirectUri } from './url-rules';

/** A role as the form shows it: fixed, apart from whether it may sign in. */
interface RoleRow {
  name: string;
  description: string;
  maySignIn: boolean;
  members: number;
}

type FieldErrors = Partial<Record<'client_id' | 'name' | 'description' | 'home_url' | 'logo_url' | 'ttl' | 'lists' | 'roles', string>>;

/**
 * The roles a new application gets from the database (migrations 011 and
 * 023), in its words and in the order the server lists them - shown on /new
 * so the form says what the application will have.
 */
const NEW_APPLICATION_ROLES: readonly RoleRow[] = [
  { name: 'ADMIN', description: "Everything in the site's admin panel", maySignIn: true, members: 0 },
  { name: 'EARLY_ACCESS', description: 'Uses the site while it lets in only chosen people (a beta, a test); given by an administrator', maySignIn: true, members: 0 },
  { name: 'EDITOR', description: "Edits the site's content in its admin panel", maySignIn: true, members: 0 },
  { name: 'MEMBER', description: 'Uses the site', maySignIn: true, members: 0 },
];

const NOBODY_MAY_SIGN_IN = 'At least one role must be allowed to sign in - switch the application off instead.';

/**
 * /admin/applications/new and /admin/applications/:id - everything an
 * application is allowed to ask for.
 *
 * Every application is a public client (PKCE, no secret), and its roles are
 * fixed - ADMIN, EDITOR, EARLY_ACCESS and MEMBER, the IAM's own entry ADMIN
 * and MEMBER (the owner's decisions of 2026-10-05 and 2026-10-19): the form
 * shows them read-only, with a tick for whether each may sign in. Whether the
 * site requires signing in is a switch. The lists (redirect URIs, logout URIs,
 * origins) are sent whole: the backend replaces each as a set, in one
 * transaction.
 *
 * The IAM's own entry (`system`) cannot be switched off or deleted, and has
 * no sign-in switch or checklist - Settings -> Signing in is its gate - so
 * those controls are not drawn for it.
 */
@Component({
  selector: 'app-application-form',
  imports: [
    RouterLink,
    AlertComponent,
    BadgeComponent,
    ButtonComponent,
    CardComponent,
    CheckboxComponent,
    EmptyStateComponent,
    ErrorStateComponent,
    IconComponent,
    PageHeaderComponent,
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

  protected readonly creating = computed(() => !this.id());
  protected readonly application = signal<AdminApplication | null>(null);
  protected readonly loading = signal(false);
  protected readonly loadError = signal<string | null>(null);

  protected readonly tab = signal('settings');
  protected readonly tabs = computed<TabItem[]>(() => [
    { id: 'settings', label: 'Settings' },
    { id: 'members', label: 'Members', count: this.members()?.members.length ?? null },
  ]);
  protected readonly members = signal<ApplicationMembersResponse | null>(null);
  protected readonly membersError = signal<string | null>(null);

  // ── The form ───────────────────────────────────────────────────────────────
  protected readonly clientId = signal('');
  protected readonly name = signal('');
  protected readonly description = signal('');
  protected readonly homeUrl = signal('');
  protected readonly logoUrl = signal('');
  protected readonly enabled = signal(true);
  protected readonly loginRequired = signal(true);
  protected readonly ttl = signal('600');
  protected readonly redirectUris = signal<string[]>([]);
  protected readonly logoutUris = signal<string[]>([]);
  protected readonly origins = signal<string[]>([]);
  protected readonly roles = signal<RoleRow[]>([]);

  protected readonly errors = signal<FieldErrors>({});
  protected readonly formError = signal<string | null>(null);
  protected readonly saving = signal(false);
  protected readonly deleting = signal(false);

  protected readonly system = computed(() => !!this.application()?.system);
  protected readonly rolesLead = computed(() =>
    this.system()
      ? "The IAM's own roles. ADMIN makes somebody an administrator here; who signs in to these pages is Settings → Signing in."
      : this.creating()
        ? 'A new application gets these four, the same in every site. Tick the ones whose holders may sign in.'
        : 'Every site has the same roles. Tick the ones whose holders may sign in.',
  );
  /** MEMBER may not sign in here: only the holders of the ticked roles get in (the Members tab says so). */
  protected readonly membersOnly = computed(() => !this.system() && this.application()?.roles.some((role) => role.name === 'MEMBER' && !role.may_sign_in) === true);

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

  protected setMaySignIn(name: string, maySignIn: boolean): void {
    this.roles.update((roles) => roles.map((role) => (role.name === name ? { ...role, maySignIn } : role)));
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
          this._toast.success(`${created.application.name} was added.`);
          void this._router.navigate(['/admin/applications', created.application.id]);
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
        // Who is blocked may have changed with the ticks.
        this.members.set(null);
        this._toast.success('Saved.');
      },
      error: (error: ApiError) => this._refused(error),
    });
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
    this.enabled.set(true);
    this.loginRequired.set(true);
    this.ttl.set('600');
    this.redirectUris.set([]);
    this.logoutUris.set([]);
    this.origins.set([]);
    this.roles.set(NEW_APPLICATION_ROLES.map((role) => ({ ...role })));
    this.errors.set({});
    this.formError.set(null);
  }

  private _fill(application: AdminApplication): void {
    this.clientId.set(application.client_id);
    this.name.set(application.name);
    this.description.set(application.description ?? '');
    this.homeUrl.set(application.home_url ?? '');
    this.logoUrl.set(application.logo_url ?? '');
    this.enabled.set(application.enabled);
    this.loginRequired.set(application.login_required);
    this.ttl.set(String(application.access_token_ttl));
    this.redirectUris.set([...application.redirect_uris]);
    this.logoutUris.set([...application.post_logout_redirect_uris]);
    this.origins.set([...application.web_origins]);
    this.roles.set(application.roles.map((role) => ({ name: role.name, description: role.description ?? '', maySignIn: role.may_sign_in, members: role.members })));
    this.errors.set({});
    this.formError.set(null);
  }

  /** The body to send, or null with the problems marked. */
  private _validate(): ApplicationInput | null {
    const errors: FieldErrors = {};
    const clientId = this.clientId().trim().toLowerCase();
    const name = this.name().trim();
    const ttl = Number(this.ttl());
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
    if (redirectUris.some((uri) => !validRedirectUri(uri)) || logoutUris.some((uri) => !validRedirectUri(uri)) || origins.some((origin) => !validOrigin(origin))) {
      errors.lists = 'Fix the marked addresses.';
    }
    // The server's rule (D6): a site switched on lets somebody in. One
    // switched off may be saved with nothing ticked.
    if (!this.system() && this.enabled() && !this.roles().some((role) => role.maySignIn)) {
      errors.roles = NOBODY_MAY_SIGN_IN;
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
      access_token_ttl: ttl,
      redirect_uris: redirectUris,
      post_logout_redirect_uris: logoutUris,
      web_origins: origins,
    };
    if (this.creating()) {
      input.client_id = clientId;
    }
    // The IAM's own entry: no switch, no ticks - nothing of them is sent.
    if (!this.system()) {
      input.enabled = this.enabled();
      input.login_required = this.loginRequired();
      input.roles = this.roles().map((role) => ({ name: role.name, may_sign_in: role.maySignIn }));
    }
    return input;
  }
}
