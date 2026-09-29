# anotoki IAM - HTTP API

The contract between the IAM backend (`backend/`), its own frontend (`frontend/`) and
every application that signs people in through it. Two halves:

- **OpenID Connect** - the standard endpoints an application uses: discovery,
  keys, authorize, token, userinfo, logout, revoke. Anything that speaks OIDC
  can use them; the SDKs in `sdk/` are the small first-party clients.
- **First-party JSON** - what the IAM's own pages call: signing in, the account
  page, the admin console. Only the IAM frontend uses these, from its own origin,
  with the IAM session cookie.

Everything below is relative to the **issuer**, which is also the public URL of
the IAM frontend. The examples use the development one:

| | Issuer |
| --- | --- |
| Development | `http://localhost:4300` (the Angular dev server, which proxies `/api`, `/.well-known` and `/setup.php` to PHP on `:8100`) |
| A real host | its own https address, as typed into the installer (`config/iam.local.php`) |

---

## Conventions

- JSON in and out, `Content-Type: application/json`, except the token and
  revocation endpoints, which take `application/x-www-form-urlencoded` as OAuth
  requires (JSON is accepted there too).
- Errors are `{ "error": "human sentence", "code": "machine_code" }` with a
  4xx/5xx status. The OIDC endpoints answer in OAuth's own shape instead:
  `{ "error": "invalid_grant", "error_description": "..." }`.
- Times are ISO-8601 strings in UTC (`2026-09-27T10:15:00Z`).
- User ids are integers. In tokens the same id is the `sub` claim, as a decimal
  string (`"1"`).
- **Before the installer has run**, every endpoint - discovery and the protocol
  endpoints included, whatever the method - answers
  `503 { "error": "...", "code": "not_set_up", "setup_url": "/setup.php" }`
  and does nothing else. The IAM's pages show "not set up yet" for it; an
  application sees a failed sign-in.

### The session cookie

The IAM frontend is signed in by one cookie, `anotoki_iam`: `HttpOnly`,
`SameSite=Lax`, `Secure` over HTTPS, `Path=/`. Its value is a random secret; the
database keeps only its SHA-256. A "keep me signed in" session sets `Max-Age`
(30 days of inactivity, 90 days at most); without it the cookie lives until the
browser closes and the session dies after a day of inactivity (a week at most).

Applications never see this cookie. They get tokens.

### CSRF

Every first-party request that is not a GET must carry the header
`X-IAM-Request: 1`. A browser will not add a custom header to a cross-origin
request without a CORS preflight, and the first-party endpoints answer no
preflight, so a page on another origin cannot make one. Requests whose `Origin`
header is present and is not the issuer's own origin are refused as well.
Missing either: `403 { code: "csrf" }`.

The OIDC endpoints are exempt: authorize and logout are top-level navigations
(GETs), and token/userinfo/revoke are called cross-origin by applications,
carrying no cookie. Exactly those three paths are exempt, compared with the path
as the router sees it - percent-decoded - so `/%61pi/...` is no way around the
guard; every other write anywhere needs the header.

---

## OpenID Connect

### `GET /.well-known/openid-configuration`

Discovery. Public, cacheable (`Cache-Control: max-age=3600`).

```json
{
  "issuer": "http://localhost:4300",
  "authorization_endpoint": "http://localhost:4300/api/oidc/authorize",
  "token_endpoint": "http://localhost:4300/api/oidc/token",
  "userinfo_endpoint": "http://localhost:4300/api/oidc/userinfo",
  "jwks_uri": "http://localhost:4300/.well-known/jwks.json",
  "end_session_endpoint": "http://localhost:4300/api/oidc/logout",
  "revocation_endpoint": "http://localhost:4300/api/oidc/revoke",
  "response_types_supported": ["code"],
  "response_modes_supported": ["query"],
  "grant_types_supported": ["authorization_code", "refresh_token"],
  "subject_types_supported": ["public"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "token_endpoint_auth_methods_supported": ["none", "client_secret_basic", "client_secret_post"],
  "code_challenge_methods_supported": ["S256"],
  "scopes_supported": ["openid", "profile", "email"],
  "claims_supported": ["sub", "iss", "aud", "exp", "iat", "auth_time", "nonce", "sid", "amr",
                       "preferred_username", "email", "email_verified", "locale", "roles"],
  "prompt_values_supported": ["none", "login", "create"],
  "ui_locales_supported": ["en", "sk"],
  "authorization_response_iss_parameter_supported": true
}
```

### `GET /.well-known/jwks.json`

The public halves of the signing keys: the active one and any retired ones still
inside their grace period. RSA, `use: "sig"`, `alg: "RS256"`, each with a `kid`.
`Cache-Control: max-age=900`.

### `GET /api/oidc/authorize`

A top-level browser navigation, never an XHR.

| Parameter | |
| --- | --- |
| `response_type` | `code` - the only one supported |
| `client_id` | the application's id (`genshin`, `japanese-academy`, ...) |
| `redirect_uri` | must equal one of the application's registered login redirect URIs, byte for byte |
| `scope` | space-separated, must contain `openid`; `profile` and `email` add claims |
| `state` | opaque, echoed back; required |
| `nonce` | echoed in the ID token; required |
| `code_challenge` | base64url(SHA-256(code_verifier)); required for public clients |
| `code_challenge_method` | `S256` |
| `prompt` | optional: `none` (never show a page), `login` (ask again even if signed in), `create` (open on the registration form) |
| `login_hint` | optional: an email to prefill |
| `max_age` | optional: seconds; an older sign-in is asked again |
| `ui_locales` | optional: `sk` or `en`, for the pages shown |

What happens:

1. An unknown `client_id`, a disabled application, or a `redirect_uri` that is
   not registered for it: the IAM **does not redirect** (it cannot trust the
   URI). It sends the browser to `/error?code=invalid_client|invalid_redirect_uri|application_disabled`.
2. Any other malformed request goes back to the redirect URI with
   `?error=invalid_request&error_description=...&state=...&iss=...`.
3. Signed in already, the sign-in satisfies `prompt`/`max_age`, and the account
   may use the application: straight back with a code -
   `302 {redirect_uri}?code=...&state=...&iss={issuer}`. No page is shown; this
   is single sign-on.
4. `prompt=none` and step 3 did not apply: back with `?error=login_required`
   (or `consent_required` never - first-party applications need no consent).
5. Otherwise the request is stored for 30 minutes and the browser goes to
   `{issuer}/login?request={id}` (or `/register?request={id}` for
   `prompt=create`), with `&lang=sk|en` when `ui_locales` named one. The IAM
   frontend finishes it with `POST /api/login/request/{id}/accept`.

The IAM's own pages, opened directly rather than by an application, take
`?next=/some/path` instead of `request` - a path on the IAM to go to after
signing in (only a path; anything else is ignored). The default is `/account`.

A code is single-use, lives 60 seconds, and is bound to the client, the redirect
URI and the PKCE challenge.

### `POST /api/oidc/token`

CORS: allowed from any origin registered as a web origin of some application;
the request is refused when the `Origin` does not belong to the client named in
it. No cookies are read. Responses carry `Cache-Control: no-store`.

**Authorization code**

```
grant_type=authorization_code
code=...
redirect_uri=...        (the same one sent to authorize)
client_id=genshin
code_verifier=...       (public clients; the plain verifier behind code_challenge)
client_secret=...       (confidential clients, or HTTP Basic instead)
```

```json
{
  "access_token": "eyJ...",
  "token_type": "Bearer",
  "expires_in": 600,
  "refresh_token": "iam_rt_...",
  "id_token": "eyJ...",
  "scope": "openid profile email"
}
```

**Refresh**

```
grant_type=refresh_token
refresh_token=iam_rt_...     (or a personal access token, iam_pat_...)
client_id=genshin
```

A refresh token is rotated on every use: the answer carries a new one and the
old one is spent. Presenting a spent token again ends the whole chain it belongs
to (reuse detection) - except within 60 seconds of its rotation, when the same
successor is handed out again, so two tabs refreshing at once do not sign
somebody out. A refresh fails with `invalid_grant` once the IAM session behind it
has ended, the account is disabled, or the account may no longer use the
application; the application should then treat the person as signed out.

A **personal access token** (`iam_pat_...`, made on the account page for
scripts) is exchanged the same way. It is never rotated, the answer has no
`refresh_token`, and it stops working when it expires or is revoked.

Errors: `400 invalid_request | invalid_grant | unsupported_grant_type | invalid_scope`,
`401 invalid_client`.

### Tokens

Both are JWTs signed RS256 with the key named by `kid`.

**Access token** - header `{"alg":"RS256","typ":"at+jwt","kid":"..."}` (RFC 9068).
Applications' backends verify it; see [integrating-an-app.md](integrating-an-app.md).

```json
{
  "iss": "http://localhost:4300",
  "sub": "1",
  "aud": "genshin",
  "client_id": "genshin",
  "iat": 1790000000,
  "exp": 1790000600,
  "jti": "5b1f...",
  "sid": "9d8c...",
  "scope": "openid profile email",
  "roles": ["ADMIN"],
  "preferred_username": "admin",
  "email": "admin@localhost",
  "email_verified": true,
  "locale": "en"
}
```

`roles` are the account's roles **in the application the token is for**, and
nothing else. An empty list means an ordinary signed-in person. `sid` names the
IAM session; it is not a secret. Lifetime is the application's
`access_token_ttl` (600 seconds unless changed).

**ID token** - header `{"alg":"RS256","typ":"JWT","kid":"..."}`. For the
application's frontend to learn who signed in.

```json
{
  "iss": "http://localhost:4300",
  "sub": "1",
  "aud": "genshin",
  "azp": "genshin",
  "iat": 1790000000,
  "exp": 1790003600,
  "auth_time": 1789990000,
  "nonce": "...",
  "sid": "9d8c...",
  "amr": ["pwd", "otp"],
  "at_hash": "...",
  "preferred_username": "admin",
  "email": "admin@localhost",
  "email_verified": true,
  "locale": "en",
  "roles": ["ADMIN"]
}
```

`amr` is how the sign-in was made: `pwd` (password), `otp` (an authenticator
code), `email` (an emailed code or link), `google`.

### `GET|POST /api/oidc/userinfo`

`Authorization: Bearer <access token>`. CORS as for the token endpoint. Answers
the claims above for the token's user - read fresh, so a changed username shows
here before the next token. `401` with `WWW-Authenticate: Bearer error="invalid_token"`
when the token is not good or its session has ended.

### `GET /api/oidc/logout`

RP-initiated logout, a top-level navigation.

| Parameter | |
| --- | --- |
| `client_id` | the application asking |
| `id_token_hint` | the ID token it holds; when it verifies and names the current session, the sign-out happens without asking |
| `post_logout_redirect_uri` | must be one of the application's registered logout redirect URIs |
| `state` | echoed back |

Ends the IAM session, spends every refresh token issued under it (in every
application), and clears the cookie; then redirects to
`post_logout_redirect_uri?state=...`. Without a verifiable `id_token_hint` the
browser is sent to `{issuer}/logout?request=...` to confirm first, so a link on
somebody else's page cannot sign people out.

### `POST /api/oidc/revoke`

RFC 7009. `token=...&client_id=...` (+ secret for confidential clients). Revokes
a refresh token - and the chain it belongs to - or a personal access token.
Always `200 {}`, whatever the token was.

---

## First-party: signing in

None of these need a session. Every non-GET needs `X-IAM-Request: 1`.

### `GET /api/public/config`

What the sign-in pages need before they can draw themselves.

```json
{
  "login_enabled": true,
  "registration_enabled": true,
  "google_enabled": true,
  "google_client_id": "105...apps.googleusercontent.com",
  "password_min": 8,
  "languages": ["en", "sk"]
}
```

### `GET /api/login/request/{id}`

A pending authorization request, so the page can say where the person is going.

```json
{
  "id": "4f3a...",
  "application": {
    "client_id": "genshin",
    "name": "Genshin Impact",
    "description": "...",
    "home_url": "http://localhost:4200",
    "logo_url": null
  },
  "prompt": null,
  "login_hint": null,
  "expires_at": "2026-09-27T10:45:00Z"
}
```

`404 { code: "request_expired" }` when it is unknown, expired or already used.

### `POST /api/login/request/{id}/accept`

Called once the person is signed in (session cookie present). Issues the code.

```json
{ "redirect": "http://localhost:4200/auth/callback?code=...&state=...&iss=..." }
```

The frontend navigates to it with `location.assign`.

Refusals, all `403` unless noted:

| `code` | Meaning | What the page offers |
| --- | --- | --- |
| `no_session` (401) | not signed in | the sign-in form |
| `request_expired` (404) | too old, or used | "Back to {app}" (`home_url`) |
| `email_not_confirmed` | account not confirmed yet | resend the confirmation |
| `password_change_required` | an admin set this password | the change-password form, then accept again |
| `access_denied` | this account may not use this application | "Use another account" / "Back to {app}" |
| `application_disabled` | switched off in the admin console | "Back" |

### `POST /api/login/request/{id}/cancel`

The person chose not to continue. `{ "redirect": "{redirect_uri}?error=access_denied&error_description=...&state=...&iss=..." }`.

The description says why, for the application to show: "The sign-in was
cancelled", or - when a signed-in account goes back from the page that refused
it - the refusal itself ("This account cannot use {app}", "Confirm your email
address first", ...). It is for people to read; an application decides nothing
on it beyond `error`.

### `POST /api/login/password`

```json
{
  "email": "...",
  "password": "...",
  "totp": "123456",          // optional: an authenticator or recovery code
  "device_token": "...",     // optional: a remembered device, skips the code
  "remember_device": true,   // optional: remember this browser for 30 days
  "remember": true           // "keep me signed in"
}
```

`200 { "user": AccountUser, "device_token": string | null }` and the session
cookie is set. Refusals:

| Status | `code` | |
| --- | --- | --- |
| 401 | `invalid_credentials` | wrong address or password (the same answer for both) |
| 403 | `password_login_unavailable` | this account signs in another way |
| 403 | `totp_required` | ask for the code, send again with `totp` |
| 403 | `totp_invalid` | the code was wrong |
| 403 | `email_not_confirmed` | offer to resend |
| 403 | `login_disabled` | signing in is switched off (IAM admins are exempt) |
| 403 | `account_disabled` | an admin switched this account off |
| 429 | `too_many_attempts` | wait |

The 401 here means "wrong credentials", not "session expired" - the frontend's
own 401 handling must leave the login endpoints alone.

### `POST /api/login/code` and `POST /api/login/code/verify`

`{ email }` → always `{ "requested": true }`; a six-digit code is mailed when the
address has a confirmed account. Then
`{ email, code, totp?, device_token?, remember_device?, remember? }` → as
`/api/login/password`; `400 invalid_code` for a wrong or spent code. The mailed
code is checked first and `totp_required` only follows a right one, so a wrong
code says nothing about two-factor; the code is spent only once both have
passed, so the same code finishes a sign-in that stopped to ask for the
authenticator.

Everywhere a second factor is asked for, an authenticator code is accepted once
(a code from an earlier or the same 30-second step as the last one accepted is
`totp_invalid`), and every miss counts against the sign-in limiter.

### `POST /api/login/google`

`{ credential, totp?, device_token?, remember_device?, remember?, language? }` -
`credential` is the Google Identity Services id token. Signs in the account that
Google identity is attached to; attaches it to the account with the same verified
address; or makes a new account (`201`, `"created": true`). Answer as
`/api/login/password` plus `created`. Refusals add `google_disabled` (403) and
`invalid_token` (400), and `429 too_many_attempts` from the same limiter as the
other ways in.

Attaching to an account whose address was never confirmed first removes
everything its registrant set - password, two-factor, recovery codes,
remembered devices, sessions, tokens, other identities - since whoever
registered it need not own the address Google has just vouched for. The same
happens when a reset link reaches such an account's mailbox.

### `POST /api/register`

`{ username, email, password, language, request? }` → `201 { "email", "sent" }`.
No session: the account works once the emailed link is opened. `request`, when
the registration started from an application, is remembered so the confirmation
can offer the way back. `409` for a taken username or address (named),
`422` for invalid input, `403 registration_disabled`, `429 too_many_attempts`.

### `POST /api/register/confirm`

`{ token }` from the emailed link → `{ "user": AccountUser, "continue_to": { "name", "url" } | null }`
and the session cookie is set. `400 invalid_token`.

### `POST /api/register/resend`

`{ email }` → always `{ "requested": true }`.

### `POST /api/password/forgot` and `POST /api/password/reset`

`{ email, request? }` → always `{ "requested": true }`.
`{ token, password, totp? }` → `{ "user": AccountUser, "continue_to": ... }`,
signs in, and takes the account back: every other session, every personal
token, every remembered device and every outside identity (an attached Google)
ends. A Google account under the same address attaches itself again at its next
sign-in. `400 invalid_token`, `422` for a short password.

With two-factor on, the link alone is not enough - it proves the mailbox, which
is what the second factor stands in case of: `403 totp_required` until `totp`
(an authenticator or recovery code) comes too, `403 totp_invalid` for a wrong
one, `429` past the limiter. Nothing is spent or changed until it passes, so the
same form goes again with the code.

### `POST /api/logout`

`{ request? }` - ends the current session and every refresh token issued under
it, and clears the cookie. Answers `{ "message": "Signed out", "redirect": string | null }`,
whether or not there was a session. `request` is the id from a logout that
needed confirming (below); `redirect` is then where the application asked to
land (`post_logout_redirect_uri?state=...`), and the page navigates there.

### `GET /api/logout/request/{id}`

A logout an application asked for without a verifiable ID token, waiting on the
`/logout?request={id}` page for the person to confirm:
`{ "id", "application": { client_id, name, home_url, logo_url } }`, or
`404 { code: "request_expired" }`. Declining is simply not confirming - the page
offers "Stay signed in" with a link to the application's `home_url`.

---

## First-party: the account page

Every endpoint here needs the session cookie; without one, `401 { code: "no_session" }`.

### `AccountUser`

```ts
interface AccountUser {
  id: number;
  username: string;
  email: string;
  email_confirmed: boolean;
  language: 'en' | 'sk';
  created_at: string;
  has_password: boolean;
  password_login_enabled: boolean;
  google_connected: boolean;
  google_email: string | null;
  totp_enabled: boolean;
  recovery_codes_remaining: number;
  trusted_devices: number;
  force_password_change: boolean;
  is_admin: boolean;              // holds ADMIN in the IAM itself
}
```

| Method + path | Body | Answer |
| --- | --- | --- |
| `GET /api/account` | | `AccountUser` |
| `PUT /api/account/profile` | `{ username?, language? }` | `AccountUser` (409 username taken) |
| `PUT /api/account/password` | `{ current_password, new_password }` | `{ message }` - other sessions and remembered devices end; `422` wrong current password |
| `POST /api/account/password/set` | `{ password }` | `AccountUser` - first password on an account without one; also clears a forced change |
| `PUT /api/account/password/enabled` | `{ enabled }` | `AccountUser` - `409 would_lock_account` / `no_password` |
| `POST /api/account/google` | `{ credential }` | `AccountUser` - `409` already connected elsewhere |
| `DELETE /api/account/google` | | `AccountUser` - `409 would_lock_account` |
| `POST /api/account/2fa/setup` | | `{ secret, uri }` (otpauth URI for the QR code) |
| `POST /api/account/2fa/enable` | `{ code }` | `{ recovery_codes: string[] }` - other sessions end |
| `POST /api/account/2fa/disable` | `{ code }` | `AccountUser` |
| `POST /api/account/2fa/recovery` | `{ code }` | `{ recovery_codes }` |
| `DELETE /api/account/devices` | | `{ ended }` |
| `GET /api/account/sessions` | | `{ sessions: SessionEntry[], failed_since_last_login }` |
| `DELETE /api/account/sessions/{id}` | | `{ message }` |
| `DELETE /api/account/sessions` | | `{ ended }` - all but this one |
| `GET /api/account/applications` | | `{ applications: AccountApplication[] }` |
| `GET /api/account/tokens` | | `{ tokens: PersonalToken[] }` |
| `POST /api/account/tokens` | `{ name, client_id, expires_in_days }` | `201 { token: "iam_pat_...", entry: PersonalToken }` - the token is shown this once |
| `DELETE /api/account/tokens/{id}` | | `{ message }` |

```ts
interface SessionEntry {
  id: number;
  method: 'password' | 'login_code' | 'google' | 'email_link';
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
  applications: string[];        // names of the applications signed into from it
}

interface AccountApplication {
  client_id: string;
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  roles: string[];
  can_use: boolean;
  last_used_at: string | null;
}

interface PersonalToken {
  id: number;
  name: string;
  client_id: string;
  application_name: string;
  created_at: string;
  expires_at: string;
  last_used_at: string | null;
  revoked_at: string | null;
}
```

---

## First-party: the admin console

Every endpoint needs the session cookie **and** the `ADMIN` role in the
application `iam`; otherwise `401 no_session` / `403 { code: "forbidden" }`.

### Users

| Method + path | |
| --- | --- |
| `GET /api/admin/users?search=&status=enabled\|disabled&confirmed=yes\|no&twoFactor=yes\|no&google=yes\|no&mustChange=yes\|no&language=&application=&role=` | `AdminUser[]`, newest first |
| `GET /api/admin/users/filters` | `{ total, disabled, admins, languages, applications: [{client_id, name, roles}], passwordMinLength }` |
| `GET /api/admin/users/{id}` | `AdminUser` |
| `GET /api/admin/users/{id}/detail` | `AdminUserDetail` |
| `POST /api/admin/users` | `{ username, email, password, language?, force_password_change?, roles?: RoleGrant[] }` → `201 AdminUser` |
| `PUT /api/admin/users/{id}` | `{ username?, email?, language?, email_confirmed?, force_password_change? }` → `AdminUser` |
| `PUT /api/admin/users/{id}/password` | `{ password, force_password_change? }` → `{ message, note }` - every session of the account ends |
| `PUT /api/admin/users/{id}/enabled` | `{ enabled }` → `AdminUser` - disabling ends every session and refresh token |
| `DELETE /api/admin/users/{id}/2fa` | turn two-factor off for somebody locked out → `AdminUser` |
| `PUT /api/admin/users/{id}/roles` | `{ roles: RoleGrant[] }` (the complete set) → `AdminUserDetail` |

Self-lockout rules carry over: an admin cannot disable themselves or take the
IAM `ADMIN` role from themselves, and the last IAM admin cannot be removed.

```ts
interface RoleGrant { client_id: string; role: string; }

interface AdminUser {
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

interface AdminUserDetail {
  account: AdminUser;
  identities: { id: number; provider: string; email: string | null; created_at: string }[];
  sessions: (SessionEntry & { current: false })[];
  login_attempts: { id: number; email: string; ip: string | null; method: string; outcome: string; created_at: string }[];
  tokens: PersonalToken[];
  active_sessions: number;
  trusted_devices: number;
  recovery_codes_remaining: number;
  failed_since_last_login: number;
}
```

### Sessions, emails, audit

| Method + path | |
| --- | --- |
| `GET /api/admin/sessions?search=&user_id=&method=&status=active\|ended` | `{ sessions: AdminSession[], total, active, methods }` (200 rows at most) |
| `DELETE /api/admin/sessions/{id}` | ends it and its refresh tokens |
| `GET /api/admin/emails?kind=&status=&recipient=&from=&to=&page=` | `{ total, page, pageSize, items: EmailLogEntry[] }` |
| `GET /api/admin/emails/filters` | `{ kinds, statuses }` |
| `GET /api/admin/audit?actor=&action=&target_type=&page=` | `{ total, page, pageSize, items: AuditEntry[] }` |
| `GET /api/admin/stats` | `{ users, disabled, admins, active_sessions, applications, signins_24h, failed_24h, emails_24h }` |

`AdminSession` is a `SessionEntry` plus `user_id`, `username`, `email`.
`EmailLogEntry` is `{ id, recipient, kind, subject, status, error, user_id, username, created_at }`.
`AuditEntry` is `{ id, actor_id, actor_username, action, target_type, target_id, details, ip, created_at }`.

### Applications

| Method + path | |
| --- | --- |
| `GET /api/admin/applications` | `AdminApplication[]` |
| `GET /api/admin/applications/{id}` | `AdminApplication` |
| `POST /api/admin/applications` | `ApplicationInput` → `201 { application, client_secret? }` |
| `PUT /api/admin/applications/{id}` | `ApplicationInput` (partial) → `AdminApplication` |
| `DELETE /api/admin/applications/{id}` | refused for `iam`; ends every token issued to it |
| `POST /api/admin/applications/{id}/secret` | confidential only → `{ client_secret }`, shown once |
| `GET /api/admin/applications/{id}/members` | `{ members: { user_id, username, email, roles: string[] }[] }` |

```ts
interface AdminApplication {
  id: number;
  client_id: string;               // [a-z0-9-], 2-64, fixed after creation
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  type: 'public' | 'confidential';
  enabled: boolean;
  access: 'everyone' | 'members'; // members = needs at least one role here
  access_token_ttl: number;        // seconds, 60-3600
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  web_origins: string[];           // allowed to call the token endpoint from a browser
  roles: { name: string; description: string | null; members: number }[];
  show_on_landing: boolean;
  sort_order: number;
  has_secret: boolean;
  system: boolean;                 // the IAM itself: cannot be deleted or disabled
  created_at: string;
  updated_at: string | null;
}
```

`ApplicationInput` is the writable subset: `client_id` (create only), `name`,
`description`, `home_url`, `logo_url`, `type`, `enabled`, `access`,
`access_token_ttl`, `redirect_uris`, `post_logout_redirect_uris`, `web_origins`,
`roles` (the complete list of `{ name, description }`; a role still granted to
somebody cannot be removed), `show_on_landing`, `sort_order`.

### Settings, keys, migrations

| Method + path | |
| --- | --- |
| `GET /api/admin/settings` | `{ settings: { name, group_name, type, options, value, updated_at, updated_by }[] }` |
| `PUT /api/admin/settings` | `{ settings: { name, value }[] }` → same |
| `GET /api/admin/keys` | `{ keys: { kid, alg, status: 'active'\|'retired', created_at, retired_at }[] }` |
| `POST /api/admin/keys/rotate` | a new active key; the old one is retired but stays in the JWKS a day |
| `DELETE /api/admin/keys/{kid}` | drops a retired key from the JWKS |
| `GET /api/admin/migrations` | `{ applied: [...], pending: [...] }` |
| `POST /api/admin/migrations/apply` | `{ applied, failed, error }` |

Settings: `login_enabled`, `registration_enabled`, `google_login_enabled`
(booleans).

### Public

`GET /api/applications` - the applications with `show_on_landing`, for the
anotoki.eu landing page: `[{ client_id, name, description, home_url, logo_url }]`.
CORS open, cacheable.
