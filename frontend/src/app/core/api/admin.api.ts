import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import {
  AdminApplication,
  AdminLanguage,
  AnnouncementAudience,
  AnnouncementBatch,
  AnnouncementCompose,
  AnnouncementDetail,
  AnnouncementDraft,
  AnnouncementPreview,
  AnnouncementRecipient,
  AnnouncementRecipientQuery,
  AnnouncementSummary,
  AnnouncementTestResult,
  AudienceCount,
  MailSenders,
  AdminSessionQuery,
  AdminSessionsResponse,
  AdminStats,
  AdminUser,
  AdminUserDetail,
  AdminUserFilters,
  AdminUserQuery,
  ApplicationInput,
  ApplicationMembersResponse,
  ApprovalDecision,
  ApprovalQueue,
  AuditEntry,
  AuditQuery,
  CreateUserBody,
  CreatedApplication,
  EmailFilters,
  EmailLogEntry,
  EmailQuery,
  KeysResponse,
  LanguageChanges,
  LanguageDeleted,
  MessageResponse,
  NewLanguage,
  Paged,
  RoleGrant,
  RotatedKey,
  SetPasswordBody,
  SetPasswordResponse,
  SettingsResponse,
  SigningKey,
  TranslationChanges,
  TranslationGrid,
  UpdateUserBody,
} from './models';
import { toParams } from './query';

/**
 * The admin panel. Every call needs the session cookie and ADMIN in the
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

  // ── Approvals ──────────────────────────────────────────────────────────────
  //
  // 503 `sign_in_rules_unavailable` until migration 012 is applied.

  /** The accounts waiting for an administrator's approval, and whether new accounts need it now. */
  approvals(): Observable<ApprovalQueue> {
    return this._http.get<ApprovalQueue>('/api/admin/approvals').pipe(map((queue) => ({ accounts: queue.accounts ?? [], account_approval: !!queue.account_approval })));
  }

  /** The person is mailed once their address is confirmed. 409 `not_waiting` when there is nothing to approve. */
  approve(userId: number): Observable<ApprovalDecision> {
    return this._http.post<ApprovalDecision>(`/api/admin/users/${userId}/approve`, {});
  }

  /** The account is switched off - nothing is deleted - and nobody is mailed. 409 `not_waiting` when there is nothing to decline. */
  decline(userId: number): Observable<ApprovalDecision> {
    return this._http.post<ApprovalDecision>(`/api/admin/users/${userId}/decline`, {});
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

  // ── Announcements ──────────────────────────────────────────────────────────
  //
  // News mailed to the people of the sites, and the addresses it may come
  // from. 503 `announcements_unavailable` until migration 013 is applied. A
  // draft is checked the same way by preview, test and create: 422 with the
  // server's sentence (`invalid_link`, `foreign_address`, `incomplete_version`, ...).

  /** What was sent, newest first, fifty a page. */
  announcements(page = 1): Observable<Paged<AnnouncementSummary>> {
    return this._http.get<Paged<AnnouncementSummary>>('/api/admin/announcements', { params: toParams({ page: page > 1 ? page : undefined }) });
  }

  announcementCompose(): Observable<AnnouncementCompose> {
    return this._http.get<AnnouncementCompose>('/api/admin/announcements/compose');
  }

  /** How many an audience reaches, and why the others are left out. */
  announcementAudience(audience: AnnouncementAudience): Observable<AudienceCount> {
    return this._http.post<AudienceCount>('/api/admin/announcements/audience', { audience });
  }

  /** The mail a reader of `language` would get - their version, or the English one. */
  previewAnnouncement(draft: AnnouncementDraft, language: string): Observable<AnnouncementPreview> {
    return this._http.post<AnnouncementPreview>('/api/admin/announcements/preview', { ...draft, language });
  }

  /** To the administrator's own address only; 429 `too_many_tests` after twenty in an hour. */
  testAnnouncement(draft: AnnouncementDraft, language: string): Observable<AnnouncementTestResult> {
    return this._http.post<AnnouncementTestResult>('/api/admin/announcements/test', { ...draft, language });
  }

  /** Makes it and its list of people (201); nothing is sent until the first batch. 422 `no_recipients` when nobody can get it. */
  createAnnouncement(draft: AnnouncementDraft, audience: AnnouncementAudience): Observable<AnnouncementDetail> {
    return this._http.post<AnnouncementDetail>('/api/admin/announcements', { ...draft, audience });
  }

  announcement(id: number): Observable<AnnouncementDetail> {
    return this._http.get<AnnouncementDetail>(`/api/admin/announcements/${id}`);
  }

  announcementRecipients(id: number, query: AnnouncementRecipientQuery = {}): Observable<Paged<AnnouncementRecipient>> {
    return this._http.get<Paged<AnnouncementRecipient>>(`/api/admin/announcements/${id}/recipients`, { params: toParams(query) });
  }

  /** The next batch, and the announcement after it; `wait` when the host's hourly allowance is used up. 409 `stopped` once it was stopped. */
  sendAnnouncementBatch(id: number): Observable<AnnouncementBatch> {
    return this._http.post<AnnouncementBatch>(`/api/admin/announcements/${id}/send`, {});
  }

  /** What is not sent yet never goes. 409 `not_sending` when it is not sending. */
  stopAnnouncement(id: number): Observable<AnnouncementDetail> {
    return this._http.post<AnnouncementDetail>(`/api/admin/announcements/${id}/stop`, {});
  }

  mailSenders(): Observable<MailSenders> {
    return this._http.get<MailSenders>('/api/admin/mail-senders');
  }

  /** At a domain the IAM sends from (422 `foreign_domain`); 409 `sender_exists`. */
  addMailSender(address: string, name: string): Observable<MailSenders> {
    return this._http.post<MailSenders>('/api/admin/mail-senders', { address, name });
  }

  /** Only an added one; what was sent from it keeps its address. */
  removeMailSender(id: string): Observable<MailSenders> {
    return this._http.delete<MailSenders>(`/api/admin/mail-senders/${encodeURIComponent(id)}`);
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

  /** The database gives it ADMIN, EDITOR and MEMBER; every application is a public client (no secret). */
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

  /** Who holds a role beyond MEMBER, and who is blocked from it. */
  members(id: number): Observable<ApplicationMembersResponse> {
    return this._http.get<ApplicationMembersResponse>(`/api/admin/applications/${id}/members`).pipe(map((response) => ({ members: response.members ?? [], blocked: response.blocked ?? [] })));
  }

  // ── Settings ───────────────────────────────────────────────────────────────

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

  // ── Languages and strings ──────────────────────────────────────────────────
  //
  // The IAM's own words (backend/api/translations.php). Until migration 006 is
  // applied the tables are not there, and every one of these answers 503
  // `translations_unavailable`: the pages then point at Migrations.

  /** Every language, the hidden ones too, in the order a chooser shows them. */
  languages(): Observable<AdminLanguage[]> {
    return this._http.get<{ languages: AdminLanguage[] }>('/api/admin/languages').pipe(map((response) => response.languages ?? []));
  }

  /** After the ones there are. 409 `language_exists`; 422 `invalid_language`, `too_many_languages` (20). */
  createLanguage(language: NewLanguage): Observable<AdminLanguage> {
    return this._http.post<{ language: AdminLanguage }>('/api/admin/languages', language).pipe(map((response) => response.language));
  }

  /** Only what changes. Switching English off is 409 `fallback_language`. */
  updateLanguage(code: string, changes: LanguageChanges): Observable<AdminLanguage> {
    return this._http.put<{ language: AdminLanguage }>(`/api/admin/languages/${encodeURIComponent(code)}`, changes).pipe(map((response) => response.language));
  }

  /**
   * The language goes with its strings, and the accounts that had it go back
   * to English - the answer says how many. 409 `fallback_language` for English,
   * `seeded_language` for one the IAM is released in.
   */
  deleteLanguage(code: string): Observable<LanguageDeleted> {
    return this._http.delete<LanguageDeleted>(`/api/admin/languages/${encodeURIComponent(code)}`);
  }

  /** Every key - the mails' too - with its string in every language. */
  translationGrid(): Observable<TranslationGrid> {
    return this._http.get<TranslationGrid>('/api/admin/translations').pipe(map(toTranslationGrid));
  }

  /**
   * Only the cells that changed. All of it is checked before any of it is
   * written, and the first thing wrong refuses the whole save (422, with `key`
   * and `language` in the error's details). Answers the grid as it is now.
   */
  saveTranslations(values: TranslationChanges): Observable<TranslationGrid> {
    return this._http.put<TranslationGrid>('/api/admin/translations', { values }).pipe(map(toTranslationGrid));
  }

  /** A language's own strings, flat: `{key: text}` - not merged with English. */
  exportTranslations(code: string): Observable<Record<string, string>> {
    return this._http.get<Record<string, string>>(`/api/admin/translations/${encodeURIComponent(code)}/export`);
  }

  /** A flat `{key: text}` into a language: a save's checks, an unknown key refused, an empty string passed over. */
  importTranslations(code: string, values: Record<string, string>): Observable<TranslationGrid> {
    return this._http.put<TranslationGrid>(`/api/admin/translations/${encodeURIComponent(code)}/import`, values).pipe(map(toTranslationGrid));
  }

  // ── Keys ───────────────────────────────────────────────────────────────────
  // (The Migrations page is the anotoki family's - @anotoki/lib - and calls
  // /api/admin/migrations itself.)

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
}

/** The grid with its two lists there whatever came: the pages read both without asking. */
function toTranslationGrid(grid: TranslationGrid): TranslationGrid {
  return { languages: grid.languages ?? [], keys: grid.keys ?? [] };
}
