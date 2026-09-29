// The shapes the IAM backend answers with, written out from docs/api.md.
//
// There is no code generator in this project: when the contract changes, this
// file changes with it, and the compiler then points at every page that has to
// follow. Field names stay snake_case exactly as they arrive, so a value can be
// traced from the network tab to the template without translating names.

export type Language = 'en' | 'sk';

export const LANGUAGES: readonly Language[] = ['en', 'sk'];

/** Every error the first-party endpoints send: a sentence for people and a code for pages. */
export interface ApiErrorBody {
  error: string;
  code: string;
  [extra: string]: unknown;
}

// ─────────────────────────────────────────────────────────────────────────────
// Signing in
// ─────────────────────────────────────────────────────────────────────────────

export interface PublicConfig {
  login_enabled: boolean;
  registration_enabled: boolean;
  google_enabled: boolean;
  google_client_id: string | null;
  password_min: number;
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
  language?: Language;
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
  language: Language;
  request?: string;
}

export interface RegisterResponse {
  email: string;
  sent: boolean;
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
  language: Language;
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
  language?: Language;
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
// The admin console
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
  roles: RoleGrant[];
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
  roles: string[];
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
  language?: Language;
  force_password_change?: boolean;
  roles?: RoleGrant[];
}

export interface UpdateUserBody {
  username?: string;
  email?: string;
  language?: Language;
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
}

export type ApplicationType = 'public' | 'confidential';
export type ApplicationAccess = 'everyone' | 'members';

export interface ApplicationRole {
  name: string;
  description: string | null;
  members: number;
}

export interface AdminApplication {
  id: number;
  /** [a-z0-9-], 2-64 characters, fixed after creation. */
  client_id: string;
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  type: ApplicationType;
  enabled: boolean;
  /** `members` needs at least one role in the application. */
  access: ApplicationAccess;
  /** Seconds, 60-3600. */
  access_token_ttl: number;
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  /** Allowed to call the token endpoint from a browser. */
  web_origins: string[];
  roles: ApplicationRole[];
  show_on_landing: boolean;
  sort_order: number;
  has_secret: boolean;
  /** The IAM itself: cannot be deleted or disabled. */
  system: boolean;
  created_at: string;
  updated_at: string | null;
}

/** The writable subset. `client_id` is sent on create only; updates may be partial. */
export interface ApplicationInput {
  client_id?: string;
  name?: string;
  description?: string | null;
  home_url?: string | null;
  logo_url?: string | null;
  type?: ApplicationType;
  enabled?: boolean;
  access?: ApplicationAccess;
  access_token_ttl?: number;
  redirect_uris?: string[];
  post_logout_redirect_uris?: string[];
  web_origins?: string[];
  roles?: { name: string; description: string | null }[];
  show_on_landing?: boolean;
  sort_order?: number;
}

export interface CreatedApplication {
  application: AdminApplication;
  client_secret?: string | null;
}

export interface ClientSecretResponse {
  client_secret: string;
}

export interface ApplicationMember {
  user_id: number;
  username: string;
  email: string;
  roles: string[];
}

export interface ApplicationMembersResponse {
  members: ApplicationMember[];
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
