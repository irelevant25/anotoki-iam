// Types for anotoki-auth.mjs / anotoki-auth.js - v1.2.0

export interface AnotokiAuthOptions {
  /** The IAM's address - its issuer - without a trailing slash: http://localhost:4300 on a development machine. */
  issuer: string;
  /** This application's client id in the IAM. */
  clientId: string;
  /** Where the IAM sends the browser back to; registered for this client, byte for byte. */
  redirectUri: string;
  /** Where signing out lands; registered for this client. Without it the IAM shows its own page. */
  postLogoutRedirectUri?: string;
  /** Defaults to "openid profile email". */
  scope?: string;
  /** localStorage key for the tokens. Defaults to "anotoki-auth:{clientId}". */
  storageKey?: string;
}

export interface AnotokiUser {
  /** The IAM user id, as a decimal string. */
  sub: string;
  preferred_username: string | null;
  email: string | null;
  email_verified: boolean;
  locale: string | null;
  /** This application's roles for the user. Empty for an ordinary account. */
  roles: string[];
  /** The account's theme, dates and clock, which every anotoki site follows; null when the IAM sends none (an older IAM, or no `profile` scope) - keep the site's own then. */
  preferences: AnotokiPreferences | null;
}

export type AnotokiTheme = 'auto' | 'light' | 'dark';
/** 6.10.2026, 06/10/2026, 10/06/2026, 2026-10-06. */
export type AnotokiDateFormat = 'dmy_dot' | 'dmy_slash' | 'mdy_slash' | 'ymd_dash';
export type AnotokiTimeFormat = '24' | '12';

export interface AnotokiPreferences {
  /** "auto" follows the operating system. */
  theme: AnotokiTheme;
  /** null: the language's own way of writing a date. */
  date_format: AnotokiDateFormat | null;
  /** null: the language's own clock. */
  time_format: AnotokiTimeFormat | null;
}

/** What savePreferences() may change; anything left out stays as it is. */
export interface AnotokiPreferenceChanges {
  theme?: AnotokiTheme;
  date_format?: AnotokiDateFormat | null;
  time_format?: AnotokiTimeFormat | null;
  /** A language code the IAM offers ("en", "sk"). */
  language?: string;
}

export interface AnotokiSavedPreferences {
  locale: string | null;
  preferences: AnotokiPreferences | null;
}

export interface AnotokiAuthError extends Error {
  /** An OAuth error ("access_denied", "login_required", "invalid_grant"...) or one of the SDK's own ("state_mismatch", "invalid_id_token", "invalid_issuer"). */
  code: string;
}

export interface AnotokiInitResult {
  /** Set when this page load was the sign-in callback: where the person was when they pressed "Sign in". */
  returnTo: string | null;
  /** Set when the callback carried a refusal, or the code could not be exchanged. */
  error: AnotokiAuthError | null;
}

export interface AnotokiLoginParameters {
  /** A path on this site to come back to. Defaults to the current one. */
  returnTo?: string;
  prompt?: 'none' | 'login' | 'create';
  loginHint?: string;
  uiLocales?: string;
  maxAge?: number;
}

export interface AnotokiAuth {
  readonly version: string;
  /** Once per page load, before drawing: finishes a callback, or restores and refreshes a stored session. */
  init(): Promise<AnotokiInitResult>;
  /** Navigates to the IAM. Never resolves in practice - the page is left. */
  login(parameters?: AnotokiLoginParameters): Promise<void>;
  /** login() opening on the registration form. */
  register(parameters?: Omit<AnotokiLoginParameters, 'prompt'>): Promise<void>;
  /** Everywhere by default (navigates to the IAM's logout); `local: true` for this site only. */
  logout(parameters?: { local?: boolean }): Promise<void>;
  /** A valid access token, refreshed when under 30 seconds remain; null when signed out or the IAM cannot be reached. */
  getAccessToken(): Promise<string | null>;
  /** After an API answered 401 to the current access token: a different, fresh one - or null (signed out, or the IAM unreachable). */
  refresh(): Promise<string | null>;
  /** fetch() with the bearer token, retried once with a fresh token after a 401. */
  fetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response>;
  /** Changes the account's preferences, for every anotoki site; user() follows at once, in every tab. Rejects with an AnotokiAuthError ("login_required", "insufficient_scope", "invalid_request", "access_denied", "network"...). */
  savePreferences(changes: AnotokiPreferenceChanges): Promise<AnotokiSavedPreferences>;
  /** Called on sign-in, sign-out, refresh and a saved preference - in this tab or another. Returns an unsubscribe. */
  onChange(listener: () => void): () => void;
  isAuthenticated(): boolean;
  user(): AnotokiUser | null;
  roles(): string[];
  hasRole(role: string): boolean;
  /** The IAM's account page. */
  accountUrl(): string;
  issuer(): string;
  clientId(): string;
}

export declare function createAuth(options: AnotokiAuthOptions): AnotokiAuth;
export declare const version: string;
