import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, throwError } from 'rxjs';
import { ApiError, isApiError } from './api-error';
import {
  AdminApplication,
  AdminSessionQuery,
  AdminSessionsResponse,
  AdminStats,
  AdminUser,
  AdminUserDetail,
  AdminUserFilters,
  AdminUserQuery,
  ApplicationInput,
  ApplicationMember,
  AuditEntry,
  AuditQuery,
  ClientSecretResponse,
  CreateUserBody,
  CreatedApplication,
  EmailFilters,
  EmailLogEntry,
  EmailQuery,
  KeysResponse,
  MessageResponse,
  MigrationItem,
  MigrationsApplyResult,
  MigrationsStatus,
  Paged,
  RoleGrant,
  RotatedKey,
  SetPasswordBody,
  SetPasswordResponse,
  SettingsResponse,
  SigningKey,
  UpdateUserBody,
} from './models';
import { toParams } from './query';

/**
 * The admin console. Every call needs the session cookie and ADMIN in the
 * application `iam`; without it the backend answers 403 `forbidden`.
 *
 * Shapes follow php/api/routes/admin.php where it says more than docs/api.md.
 */
@Injectable({ providedIn: 'root' })
export class AdminApi {
  private readonly _http = inject(HttpClient);

  // ── Users ──────────────────────────────────────────────────────────────────

  users(query: AdminUserQuery = {}): Observable<AdminUser[]> {
    return this._http.get<AdminUser[]>('/api/admin/users', { params: toParams(query) });
  }

  userFilters(): Observable<AdminUserFilters> {
    return this._http.get<AdminUserFilters>('/api/admin/users/filters').pipe(map((filters) => ({ ...filters, languages: filters.languages ?? [], applications: filters.applications ?? [] })));
  }

  user(id: number): Observable<AdminUser> {
    return this._http.get<AdminUser>(`/api/admin/users/${id}`);
  }

  userDetail(id: number): Observable<AdminUserDetail> {
    return this._http.get<AdminUserDetail>(`/api/admin/users/${id}/detail`);
  }

  createUser(body: CreateUserBody): Observable<AdminUser> {
    return this._http.post<AdminUser>('/api/admin/users', body);
  }

  /** Only the fields that changed: an empty body is refused as `nothing_to_change`. */
  updateUser(id: number, body: UpdateUserBody): Observable<AdminUser> {
    return this._http.put<AdminUser>(`/api/admin/users/${id}`, body);
  }

  /** Every session of the account ends - except the admin's own, when they reset themselves. */
  setUserPassword(id: number, body: SetPasswordBody): Observable<SetPasswordResponse> {
    return this._http.put<SetPasswordResponse>(`/api/admin/users/${id}/password`, body);
  }

  /** Disabling ends every session and refresh token of the account. `self_lockout` / `last_admin` refuse it. */
  setUserEnabled(id: number, enabled: boolean): Observable<AdminUser> {
    return this._http.put<AdminUser>(`/api/admin/users/${id}/enabled`, { enabled });
  }

  resetUserTwoFactor(id: number): Observable<AdminUser> {
    return this._http.delete<AdminUser>(`/api/admin/users/${id}/2fa`);
  }

  /** The complete set of grants: anything left out is taken away. */
  setUserRoles(id: number, roles: RoleGrant[]): Observable<AdminUserDetail> {
    return this._http.put<AdminUserDetail>(`/api/admin/users/${id}/roles`, { roles });
  }

  // ── Sessions, emails, audit ────────────────────────────────────────────────

  sessions(query: AdminSessionQuery = {}): Observable<AdminSessionsResponse> {
    return this._http.get<AdminSessionsResponse>('/api/admin/sessions', { params: toParams(query) });
  }

  endSession(id: number): Observable<MessageResponse> {
    return this._http.delete<MessageResponse>(`/api/admin/sessions/${id}`);
  }

  emails(query: EmailQuery = {}): Observable<Paged<EmailLogEntry>> {
    return this._http.get<Paged<EmailLogEntry>>('/api/admin/emails', { params: toParams(query) });
  }

  emailFilters(): Observable<EmailFilters> {
    return this._http.get<EmailFilters>('/api/admin/emails/filters');
  }

  audit(query: AuditQuery = {}): Observable<Paged<AuditEntry>> {
    return this._http.get<Paged<AuditEntry>>('/api/admin/audit', { params: toParams(query) });
  }

  stats(): Observable<AdminStats> {
    return this._http.get<AdminStats>('/api/admin/stats');
  }

  // ── Applications ───────────────────────────────────────────────────────────
  //
  // `{id}` is the numeric `AdminApplication.id`, never the client id.

  applications(): Observable<AdminApplication[]> {
    return this._http.get<AdminApplication[]>('/api/admin/applications');
  }

  application(id: number): Observable<AdminApplication> {
    return this._http.get<AdminApplication>(`/api/admin/applications/${id}`);
  }

  /** `client_secret` is set for a confidential application, and shown once. */
  createApplication(input: ApplicationInput): Observable<CreatedApplication> {
    return this._http.post<CreatedApplication>('/api/admin/applications', input);
  }

  updateApplication(id: number, input: ApplicationInput): Observable<AdminApplication> {
    return this._http.put<AdminApplication>(`/api/admin/applications/${id}`, input);
  }

  /** Refused for the IAM itself. Every token issued to the application stops working. */
  deleteApplication(id: number): Observable<MessageResponse> {
    return this._http.delete<MessageResponse>(`/api/admin/applications/${id}`);
  }

  /** Confidential applications only. The secret is shown once. */
  newSecret(id: number): Observable<ClientSecretResponse> {
    return this._http.post<ClientSecretResponse>(`/api/admin/applications/${id}/secret`, {});
  }

  members(id: number): Observable<ApplicationMember[]> {
    return this._http.get<{ members: ApplicationMember[] }>(`/api/admin/applications/${id}/members`).pipe(map((response) => response.members ?? []));
  }

  // ── Settings, keys, migrations ─────────────────────────────────────────────

  settings(): Observable<SettingsResponse> {
    return this._http.get<SettingsResponse>('/api/admin/settings');
  }

  /**
   * Only the settings that changed. Values go back as text - a switch is
   * `"true"` or `"false"` - which is how the settings table stores them and
   * the only form the backend accepts for a boolean.
   */
  saveSettings(settings: { name: string; value: string }[]): Observable<SettingsResponse> {
    return this._http.put<SettingsResponse>('/api/admin/settings', { settings });
  }

  keys(): Observable<SigningKey[]> {
    return this._http.get<KeysResponse>('/api/admin/keys').pipe(map((response) => response.keys ?? []));
  }

  /** A new active key; the old one is retired but stays in the JWKS a day. */
  rotateKey(): Observable<RotatedKey> {
    return this._http.post<RotatedKey>('/api/admin/keys/rotate', {});
  }

  /** Only a retired key can go (`not_retired` otherwise). */
  deleteKey(kid: string): Observable<MessageResponse> {
    return this._http.delete<MessageResponse>(`/api/admin/keys/${encodeURIComponent(kid)}`);
  }

  migrations(): Observable<MigrationsStatus> {
    return this._http.get<{ applied?: unknown[]; pending?: unknown[] }>('/api/admin/migrations').pipe(
      map((response) => ({
        applied: (response.applied ?? []).map(toMigrationItem),
        pending: (response.pending ?? []).map(toMigrationItem),
      })),
    );
  }

  /**
   * Applies every pending file, stopping at the first that fails.
   *
   * A failed run answers 500 with the same body as a good one - what did
   * apply, and which file stopped it - so that failure is turned back into a
   * result here: the page reports it, it is not an error about the request.
   */
  applyMigrations(): Observable<MigrationsApplyResult> {
    return this._http.post<MigrationsApplyResult>('/api/admin/migrations/apply', {}).pipe(
      map((result) => ({ applied: result.applied ?? [], failed: result.failed ?? null, error: result.error ?? null })),
      catchError((error: unknown) => {
        if (isApiError(error) && typeof error.details['failed'] === 'string') {
          return of(migrationFailure(error));
        }
        return throwError(() => error);
      }),
    );
  }
}

function migrationFailure(error: ApiError): MigrationsApplyResult {
  const applied = error.details['applied'];
  return {
    applied: Array.isArray(applied) ? applied.map(String) : [],
    failed: String(error.details['failed']),
    error: error.message,
  };
}

/** A row of the migrations table, or a bare file name from the pending list. */
function toMigrationItem(entry: unknown): MigrationItem {
  if (typeof entry === 'string') {
    return { name: entry, applied_at: null };
  }

  const row = (entry ?? {}) as Record<string, unknown>;
  const name = row['filename'] ?? row['name'] ?? '';
  const appliedAt = row['applied_at'] ?? null;

  return { name: String(name), applied_at: appliedAt === null ? null : String(appliedAt) };
}
