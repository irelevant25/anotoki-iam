// The shapes the IAM backend answers with, written out from docs/api.md.
//
// There is no code generator in this project: when the contract changes, this
// file changes with it, and the compiler then points at every page that has to
// follow. Field names stay snake_case exactly as they arrive, so a value can be
// traced from the network tab to the template without translating names.

// A language is its code, as the `languages` table keeps it: `xx` or `xx-yy`,
// lower case. Which ones exist is the admin panel's business and arrives with
// the words themselves (TranslationBundle below), so it is a plain string here -
// there is no fixed list for the compiler to hold anybody to.

/** Every error the first-party endpoints send: a sentence for people and a code for pages. */
export interface ApiErrorBody {
  error: string;
  code: string;
  [extra: string]: unknown;
}

// ─────────────────────────────────────────────────────────────────────────────
// Signing in
// ─────────────────────────────────────────────────────────────────────────────

/** A language the pages are offered in. */
export interface SiteLanguage {
  code: string;
  /** In English ("Slovak") - what the admin panel lists it as. */
  name: string;
  /** As its speakers write it ("Slovenčina") - what a person choosing it reads. */
  native_name: string;
}

/**
 * `GET /api/translations/{code}`: the site's words in one language, with
 * English merged underneath so a string nobody translated yet is still a
 * sentence, the languages on offer, and English itself beside it (left out
 * when `language` is `en`) for the admin panel, which is English whatever
 * the person reads.
 *
 * `language` is the one that answered: the code asked for; its language for a
 * code with a region that is not offered itself (`sk` for `sk-sk`); `en` when
 * what was asked for is unknown or switched off.
 */
export interface TranslationBundle {
  language: string;
  languages: SiteLanguage[];
  values: Record<string, string>;
  english?: Record<string, string>;
}

export interface PublicConfig {
  login_enabled: boolean;
  registration_enabled: boolean;
  /** A new account waits for an administrator's approval before it can sign in (Settings -> New accounts need approval). */
  registration_approval: boolean;
  google_enabled: boolean;
  google_client_id: string | null;
  password_min: number;
  /** The codes of the offered languages. */
  languages: string[];
}

/** The application a pending authorization or logout request came from. */
export interface RequestApplication {
  client_id: string;
  name: string;
  description?: string | null;
  home_url: string | null;
  logo_url: string | null;
}

export interface LoginRequest {
  id: string;
  application: RequestApplication;
  prompt: string | null;
  login_hint: string | null;
  expires_at: string;
}

export interface LogoutRequest {
  id: string;
  application: RequestApplication;
}

export interface RedirectResponse {
  redirect: string;
}

/** What every way of signing in accepts on top of its own credentials. */
export interface SignInOptions {
  /** An authenticator code or a recovery code, once the account asked for one. */
  totp?: string;
  /** A remembered browser, which skips the code. */
  device_token?: string;
  /** Remember this browser for thirty days. */
  remember_device?: boolean;
  /** "Keep me signed in". */
  remember?: boolean;
}

export interface PasswordSignInBody extends SignInOptions {
  email: string;
  password: string;
}

export interface CodeSignInBody extends SignInOptions {
  email: string;
  code: string;
}

export interface GoogleSignInBody extends SignInOptions {
  credential: string;
  language?: string;
}

export interface SignInResponse {
  user: AccountUser;
  device_token: string | null;
  /** Only from Google: true when the sign-in made a new account. */
  created?: boolean;
}

export interface RequestedResponse {
  requested: boolean;
}

export interface RegisterBody {
  username: string;
  email: string;
  password: string;
  language: string;
  request?: string;
}

export interface RegisterResponse {
  email: string;
  sent: boolean;
  /** The account waits for an administrator's approval before it can sign in anywhere. */
  approval_pending: boolean;
}

export interface ContinueTo {
  name: string;
  url: string;
}

/** What confirming an address and resetting a password both answer. */
export interface SignedInWithLink {
  user: AccountUser;
  continue_to: ContinueTo | null;
}

export interface LogoutResponse {
  message: string;
  redirect: string | null;
}

// ─────────────────────────────────────────────────────────────────────────────
// The account page
// ─────────────────────────────────────────────────────────────────────────────

export interface AccountUser {
  id: number;
  username: string;
  email: string;
  email_confirmed: boolean;
  language: string;
  created_at: string;
  has_password: boolean;
  password_login_enabled: boolean;
  google_connected: boolean;
  google_email: string | null;
  totp_enabled: boolean;
  recovery_codes_remaining: number;
  trusted_devices: number;
  force_password_change: boolean;
  /** Holds ADMIN in the IAM itself. */
  is_admin: boolean;
}

export type SessionMethod = 'password' | 'login_code' | 'google' | 'email_link';

export interface SessionEntry {
  id: number;
  method: SessionMethod | string;
  mfa: boolean;
  ip: string | null;
  user_agent: string | null;
  created_at: string;
  last_seen_at: string | null;
  expires_at: string;
  revoked_at: string | null;
  revoked_reason: string | null;
  active: boolean;
  current: boolean;
  /** Names of the applications signed into from it. */
  applications: string[];
}

export interface SessionsResponse {
  sessions: SessionEntry[];
  failed_since_last_login: number;
}

export interface AccountApplication {
  client_id: string;
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  roles: string[];
  can_use: boolean;
  last_used_at: string | null;
}

export interface PersonalToken {
  id: number;
  name: string;
  client_id: string;
  application_name: string;
  created_at: string;
  expires_at: string;
  last_used_at: string | null;
  revoked_at: string | null;
}

export interface CreateTokenBody {
  name: string;
  client_id: string;
  expires_in_days: number;
}

export interface CreatedToken {
  /** `iam_pat_...` - shown this once and never again. */
  token: string;
  entry: PersonalToken;
}

export interface ProfileBody {
  username?: string;
  language?: string;
}

export interface TwoFactorSetup {
  secret: string;
  /** The otpauth:// URI the QR code carries. */
  uri: string;
}

export interface RecoveryCodes {
  recovery_codes: string[];
}

export interface MessageResponse {
  message: string;
}

export interface EndedResponse {
  ended: number;
}

// ─────────────────────────────────────────────────────────────────────────────
// The admin panel
// ─────────────────────────────────────────────────────────────────────────────

export interface RoleGrant {
  client_id: string;
  role: string;
}

export interface AdminUser {
  id: number;
  username: string;
  email: string;
  email_confirmed: boolean;
  language: string;
  disabled: boolean;
  totp_enabled: boolean;
  force_password_change: boolean;
  has_password: boolean;
  google_connected: boolean;
  google_email: string | null;
  /** Every grant, MEMBER included (every account holds MEMBER everywhere unless it was taken away). */
  roles: RoleGrant[];
  /**
   * Client ids of the switched-on sites this account is blocked from: it holds
   * no role there that may sign in, and its MEMBER there was taken away or it
   * holds another role there. Empty while migration 010 is pending.
   */
  blocked_in: string[];
  /** False: made by signing up while new accounts needed approval, and not approved yet - it signs in nowhere. */
  approved: boolean;
  last_login_at: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface AdminIdentity {
  id: number;
  provider: string;
  email: string | null;
  created_at: string;
}

export interface AdminLoginAttempt {
  id: number;
  email: string;
  ip: string | null;
  method: string;
  outcome: string;
  created_at: string;
}

export interface AdminUserDetail {
  account: AdminUser;
  identities: AdminIdentity[];
  sessions: SessionEntry[];
  login_attempts: AdminLoginAttempt[];
  tokens: PersonalToken[];
  active_sessions: number;
  trusted_devices: number;
  recovery_codes_remaining: number;
  failed_since_last_login: number;
}

/** An application as the user filters describe it: enough for the role editor and the role filter. */
export interface FilterApplication {
  client_id: string;
  name: string;
  /** The IAM's own entry: its roles are never asked at sign-in (Settings -> Signing in is its gate). */
  system: boolean;
  roles: string[];
  /** The roles whose holders may sign in (every role while migration 010 is pending). */
  sign_in_roles: string[];
}

export interface AdminUserFilters {
  total: number;
  disabled: number;
  admins: number;
  languages: string[];
  applications: FilterApplication[];
  passwordMinLength: number;
}

export type YesNo = 'yes' | 'no';

export interface AdminUserQuery {
  search?: string;
  status?: 'enabled' | 'disabled';
  confirmed?: YesNo;
  twoFactor?: YesNo;
  google?: YesNo;
  mustChange?: YesNo;
  language?: string;
  application?: string;
  role?: string;
}

export interface CreateUserBody {
  username: string;
  email: string;
  password: string;
  language?: string;
  force_password_change?: boolean;
  roles?: RoleGrant[];
}

export interface UpdateUserBody {
  username?: string;
  email?: string;
  language?: string;
  email_confirmed?: boolean;
  force_password_change?: boolean;
}

export interface SetPasswordBody {
  password: string;
  force_password_change?: boolean;
}

export interface SetPasswordResponse {
  message: string;
  note: string;
}

export interface AdminSession extends SessionEntry {
  user_id: number;
  username: string;
  email: string;
}

export interface AdminSessionQuery {
  search?: string;
  user_id?: number;
  method?: string;
  status?: 'active' | 'ended';
}

export interface AdminSessionsResponse {
  sessions: AdminSession[];
  total: number;
  active: number;
  methods: string[];
}

export interface Paged<T> {
  total: number;
  page: number;
  pageSize: number;
  items: T[];
}

export interface EmailLogEntry {
  id: number;
  recipient: string;
  kind: string;
  subject: string | null;
  status: string;
  error: string | null;
  user_id: number | null;
  username: string | null;
  created_at: string;
}

export interface EmailQuery {
  kind?: string;
  status?: string;
  recipient?: string;
  from?: string;
  to?: string;
  page?: number;
}

export interface EmailFilters {
  kinds: string[];
  statuses: string[];
}

export interface AuditEntry {
  id: number;
  actor_id: number | null;
  actor_username: string | null;
  action: string;
  target_type: string | null;
  target_id: string | number | null;
  /** JSON the backend stored with the entry - an object, or already a string. */
  details: unknown;
  ip: string | null;
  created_at: string;
}

export interface AuditQuery {
  actor?: string;
  action?: string;
  target_type?: string;
  page?: number;
}

export interface AdminStats {
  users: number;
  disabled: number;
  admins: number;
  active_sessions: number;
  applications: number;
  signins_24h: number;
  failed_24h: number;
  emails_24h: number;
  /** Accounts waiting for an administrator's approval, switched-off ones left out (the Approvals page). */
  awaiting_approval: number;
}

export interface ApplicationRole {
  name: string;
  description: string | null;
  /** Whether holding this role lets somebody sign in to the application. Means nothing for the IAM's own entry. */
  may_sign_in: boolean;
  /** How many accounts hold it. */
  members: number;
}

/**
 * An application as the admin panel shows it. Every application is a public
 * client (PKCE, no secret), and its roles are fixed - ADMIN, EDITOR and MEMBER
 * (the IAM's own entry ADMIN and MEMBER), plus any made before they were: the
 * panel changes only whether each may sign in.
 */
export interface AdminApplication {
  id: number;
  /** [a-z0-9-], 2-64 characters, fixed after creation. */
  client_id: string;
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  enabled: boolean;
  /** Whether the site sends everybody to sign in (true), or lets visitors in. Always true for the IAM's own entry. */
  login_required: boolean;
  /** Seconds, 60-3600. */
  access_token_ttl: number;
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  /** Allowed to call the token endpoint from a browser. */
  web_origins: string[];
  roles: ApplicationRole[];
  /** The IAM itself: cannot be deleted or disabled, has no sign-in switch. */
  system: boolean;
  created_at: string;
  updated_at: string | null;
}

/** A role as a save sends it: the roles are fixed, so only `may_sign_in` changes (left out, it is kept). */
export interface ApplicationRoleInput {
  name: string;
  may_sign_in?: boolean;
}

/**
 * The writable subset. `client_id` is sent on create only; updates may be
 * partial. `roles`, on an update, names every role the application has; on a
 * create, the roles whose tick it sets.
 */
export interface ApplicationInput {
  client_id?: string;
  name?: string;
  description?: string | null;
  home_url?: string | null;
  logo_url?: string | null;
  enabled?: boolean;
  /** Not for the IAM's own entry. */
  login_required?: boolean;
  access_token_ttl?: number;
  redirect_uris?: string[];
  post_logout_redirect_uris?: string[];
  web_origins?: string[];
  roles?: ApplicationRoleInput[];
}

export interface CreatedApplication {
  application: AdminApplication;
}

/** Somebody holding a role in the application beyond MEMBER, with every role they hold there. */
export interface ApplicationMember {
  user_id: number;
  username: string;
  email: string;
  roles: string[];
}

/** An account (switched on) blocked from the application: MEMBER taken away, or only roles that may not sign in. */
export interface BlockedAccount {
  user_id: number;
  username: string;
  email: string;
}

export interface ApplicationMembersResponse {
  members: ApplicationMember[];
  /** Always empty for the IAM's own entry. */
  blocked: BlockedAccount[];
}

/** An account waiting for an administrator's approval before it can sign in anywhere. */
export interface PendingAccount {
  user_id: number;
  username: string;
  email: string;
  email_confirmed: boolean;
  created_at: string;
}

/** `GET /api/admin/approvals`: the accounts waiting, oldest first - switched-off ones left out. */
export interface ApprovalQueue {
  accounts: PendingAccount[];
  /** Settings -> New accounts need approval. */
  account_approval: boolean;
}

/** What approving or declining answers: the account as it is now, and whether it was mailed. */
export interface ApprovalDecision {
  account: AdminUser;
  mailed: boolean;
}

export interface Setting {
  name: string;
  group_name: string;
  /** `boolean`, `text` or `choice`. */
  type: string;
  /** The allowed values of a `choice`. */
  options: string[];
  /** Always text: a boolean switch is `"true"` or `"false"`, and is sent back the same way. */
  value: string;
  updated_at: string | null;
  /** The username of whoever changed it last. */
  updated_by: string | null;
}

export interface SettingsResponse {
  settings: Setting[];
}

/** A language as the admin panel sees it: the hidden ones too. */
export interface AdminLanguage extends SiteLanguage {
  /** Offered: on the switcher, in discovery, and a language an account can have. */
  enabled: boolean;
  /** Its place among the others; the lowest comes first. */
  sort_order: number;
  /** The migrations write strings for it, so it cannot be deleted. */
  seeded: boolean;
  /** How many accounts have it as their language. */
  accounts: number;
}

/**
 * `POST /api/admin/languages`. It goes after the ones there are. `enabled`
 * left out would mean offered at once; the panel always says which.
 */
export interface NewLanguage {
  code: string;
  name: string;
  native_name: string;
  enabled: boolean;
}

/** What `PUT /api/admin/languages/{code}` may change; what is left out stays. */
export interface LanguageChanges {
  name?: string;
  native_name?: string;
  enabled?: boolean;
  sort_order?: number;
}

/** `DELETE /api/admin/languages/{code}`: how many accounts had it, and went back to English. */
export interface LanguageDeleted {
  accounts: number;
}

/** One key of the IAM's words - a page's or a mail's - with its string in every language that has one. */
export interface TranslationKeyRow {
  name: string;
  /** Where it is shown, and what its {placeholders} are; empty when nothing was written about it. */
  description: string | null;
  values: Record<string, string>;
}

/** `GET` and `PUT /api/admin/translations`, and an import's answer: every language and every key, ordered by name. */
export interface TranslationGrid {
  languages: AdminLanguage[];
  keys: TranslationKeyRow[];
}

/**
 * The cells a save changes: key -> language code -> text. A blank one deletes
 * that language's string (the key then reads in English) - except English's,
 * which is refused.
 *
 * A refusal about one string says which in `ApiError.details`: `key`,
 * `language`, and `placeholder` or `keys` where it is about those.
 */
export type TranslationChanges = Record<string, Record<string, string>>;

export interface SigningKey {
  kid: string;
  alg: string;
  status: 'active' | 'retired';
  created_at: string;
  retired_at: string | null;
}

export interface KeysResponse {
  keys: SigningKey[];
}

/**
 * One migration, flattened.
 *
 * `applied` arrives as rows of the migrations table (`filename`, `applied_at`)
 * and `pending` as bare file names; the admin API service reads both into this.
 */
export interface MigrationItem {
  name: string;
  applied_at: string | null;
}

export interface MigrationsStatus {
  applied: MigrationItem[];
  pending: MigrationItem[];
}

/** What applying answers: the files that ran, and the one that stopped the run, if any. */
export interface MigrationsApplyResult {
  applied: string[];
  failed: string | null;
  error: string | null;
}

export interface RotatedKey {
  message: string;
  kid: string;
}
