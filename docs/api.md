# anotoki IAM - HTTP API

The contract between the IAM backend (`backend/`), its own frontend (`frontend/`) and
every application that signs people in through it. Two halves:

- **OpenID Connect** - the standard endpoints an application uses: discovery,
  keys, authorize, token, userinfo, logout, revoke. Anything that speaks OIDC
  can use them; the SDKs in `sdk/` are the small first-party clients.
- **First-party JSON** - what the IAM's own pages call: signing in, the account
  page, the admin panel. Only the IAM frontend uses these, from its own origin,
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
  "token_endpoint_auth_methods_supported": ["none"],
  "revocation_endpoint_auth_methods_supported": ["none"],
  "code_challenge_methods_supported": ["S256"],
  "scopes_supported": ["openid", "profile", "email"],
  "claims_supported": ["sub", "iss", "aud", "exp", "iat", "auth_time", "nonce", "sid", "amr",
                       "preferred_username", "email", "email_verified", "locale", "roles"],
  "prompt_values_supported": ["none", "login", "create"],
  "ui_locales_supported": ["en", "sk"],
  "authorization_response_iss_parameter_supported": true
}
```

`ui_locales_supported` is the languages the IAM offers - the ones switched on
in the admin panel (Languages), in its order: codes of the shape `xx` or
`xx-yy`, lower case. A fresh install offers `en` and `sk`. Before migration
006 is applied, or while the database cannot be reached, it answers
`["en", "sk"]` - and such an answer, like any other, may be cached for the hour.

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
| `ui_locales` | optional: space-separated language tags, the site's first choice first, for the pages shown. The first one the IAM offers is used - by its lower-cased whole (`pt-BR` for `pt-br`), else by its first subtag (`sk-SK` for `sk`); the rest are ignored |

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
   `prompt=create`), with `&lang={code}` when `ui_locales` named an offered
   language. The IAM
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
code_verifier=...       (the plain verifier behind code_challenge)
```

Every application is a public client (migration 011, the owner's decision of
2026-10-05): it names itself with `client_id` and proves the code with PKCE,
which authorize asks of every client. There are no client secrets: a request
to the token or revocation endpoint that carries one - `client_secret` in the
body, even empty, or an `Authorization: Basic` header - is refused
`401 invalid_client` "Public clients only - send client_id and the PKCE
code_verifier, no secret", and nothing in it is read.

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
nothing else. Every account holds `MEMBER` in every application (migration
010), so an ordinary signed-in person's list is `["MEMBER"]` - treat it as
nobody in particular, as an empty list was before 010. `sid` names the
IAM session; it is not a secret. Lifetime is the application's
`access_token_ttl` (600 seconds unless changed).

`locale` (with the `profile` scope, in both tokens and in userinfo) is the
account's language while the IAM offers it, else `en`. The account keeps a
language that was switched off, and `locale` says it again once it is back on.
A token minted before a language was switched off or deleted carries it for
that token's lifetime - a site checks `locale` against its own languages and
treats anything else as its default.

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

RFC 7009. `token=...&client_id=...` (no secret: public clients only). Revokes
a refresh token - and the chain it belongs to - or a personal access token.
Always `200 {}`, whatever the token was.

### `GET /api/public/applications/{client_id}`

Is sign-in required here? The one public answer about an application, for the
sites' **backends** (the PHP SDK's `anotoki_iam_login_required()` reads it -
`sdk/README.md`, `docs/integrating-an-app.md`). No session, no token, no CSRF
header; in no CORS list (a browser never needs it).

```json
{ "client_id": "survey", "name": "Surveys", "login_required": true }
```

`login_required` is the switch on the application's page in the admin panel
(Applications -> the site -> "Sign-in required"): `true` - the site sends
everybody to sign in; `false` - it lets visitors in without an account
(genshin and the build analyzer, as migration 010 set them). These three
fields and nothing else; `Cache-Control: public, max-age=60` - a switch
turned in the admin panel reaches the sites within a minute or so.

- `404 not_found` for an unknown client id, a malformed one (not 2-64
  lowercase letters, digits and dashes), a switched-off application
  (`enabled: false`) and the IAM's own entry (`iam`, system). A site reads a
  404 as "sign-in required" and keeps it.
- `503 sign_in_rules_unavailable` for an application that exists while
  migration 010 is pending (there is no switch to read yet);
  `503 not_set_up` before the installer. A site keeps the last answer it had,
  or requires sign-in when it never had one.
- Both are `no-store`.

What it gives away: to anybody who knows a client id, that an enabled site
with that client id exists, and its name - as the sign-in page does for
anybody who starts a sign-in to it (the public list of sites,
`GET /api/applications`, names every one switched on anyway). Nothing about people,
roles or addresses.

The OIDC error code `login_required` (authorize, `prompt=none`, above) is
unrelated: the same two words for "nobody is signed in here".

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

`languages` is the codes on offer, in the admin panel's order - the same list
as discovery's `ui_locales_supported`, and like it `["en", "sk"]` before
migration 006 or while the database cannot be reached. The pages give a new
account the reader's language settled against this list, even while they are
still showing the compiled English.

### `GET /api/translations/{code}`

The pages' words in one language. The same for everybody: no cookie is read or
set, a page whose session has ended still reads in its language, and it is in
neither CORS list - only the IAM's own pages read it. Nothing else lives under
`/api/translations` (`/api/translations` alone is a 404, a write a 405).

```json
{
  "language": "sk",
  "languages": [
    { "code": "en", "name": "English", "native_name": "English" },
    { "code": "sk", "name": "Slovak", "native_name": "Slovenčina" }
  ],
  "values": { "common.save": "Uložiť", "...": "..." },
  "english": { "common.save": "Save", "...": "..." }
}
```

- `language` is the language the answer is in, which need not be the code
  asked for. An offered code is itself (`pt-br` when `pt-br` is on). A code with
  a region that is not offered itself is answered by its base language when that
  is offered - `sk-sk`, a Slovak browser's first wish, gets the very bytes and
  ETag of `sk`, so a first visit is one request. Anything the IAM does not have,
  or has switched off, is answered in English (`language: "en"`). Only this
  route reads a region this way; discovery and `ui_locales` match whole codes
  and first subtags as described above.
- `languages` is the offered languages, in order, with both names.
- `values` is every page key: the language's own strings with English under
  them, so a key it has no string for reads in English.
- `english` is English's own strings, for the admin panel, which is English
  whatever the reader chose. Left out when `language` is `en`.
- No `mail.` key is in either map: the mails' words are the server's.

`404 not_found` for a code that is not `xx` or `xx-yy` in small letters (`EN`,
`en-GB`, `en_gb` are no codes), before any query. `503 translations_unavailable`
while migration 006 is pending, or English has no strings at all: not
`not_set_up`, which would send the pages to the installer's notice - they keep
the English compiled into the build meanwhile.

Caching: `ETag` (an MD5 of the body, in quotes) and `Cache-Control: no-cache`,
so the browser asks every time and is told `304` (empty, with the same two
headers) when nothing changed. `If-None-Match` is compared the weak way: any
tag of its list, or `*`, with or without `W/` in front, and with or without
`-gzip`, `-br` or `-zstd` just inside the closing quote - what a compressing
Apache (mod_deflate, mod_brotli) or proxy does to the tag on the way out.
Without that, behind such a server no browser would ever get the 304.

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
| `access_denied` | this account holds no role in this application that may sign in (its MEMBER taken away, or MEMBER not allowed to sign in there and no other role) | "Use another account" / "Back to {app}" |
| `application_disabled` | switched off in the admin panel | "Back" |

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
`language` (here and on `POST /api/login/google`) is lower-cased and trimmed; one
the IAM does not offer makes an English account rather than a refusal.

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
  language: string;               // a language code, xx or xx-yy
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
| `PUT /api/account/profile` | `{ username?, language? }` | `AccountUser` (409 username taken; `422 invalid_language` for a language not on offer) |
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
  roles: string[];               // MEMBER among them, unless it was taken away
  can_use: boolean;              // false: switched off, or no role here that may sign in
  last_used_at: string | null;
}
// Not listed: an application whose roles keep the account out while it holds
// none there but MEMBER - one it was never invited to.

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

## First-party: the admin panel

Every endpoint needs the session cookie **and** the `ADMIN` role in the
application `iam`; otherwise `401 no_session` / `403 { code: "forbidden" }`.

### Users

| Method + path | |
| --- | --- |
| `GET /api/admin/users?search=&status=enabled\|disabled&confirmed=yes\|no&twoFactor=yes\|no&google=yes\|no&mustChange=yes\|no&language=&application=&role=` | `AdminUser[]`, newest first |
| `GET /api/admin/users/filters` | `{ total, disabled, admins, languages, applications: FilterApplication[], passwordMinLength }` |
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

A new account holds `MEMBER` in every application, however it is made (the
database does it, migration 010): `POST` gives it besides the `roles` named,
and a `MEMBER` named there is no error. `PUT .../roles` is the complete set,
so a set without a site's `MEMBER` takes it away - that blocks the person
from the site, unless they hold another role there that may sign in.

The list's `application` filter without a `role` finds the accounts holding a
role there beyond `MEMBER` (every account holds `MEMBER`); with a role,
`MEMBER` included, exactly its holders.

`blocked_in` lists the switched-on sites (never `iam`) an account is blocked
from: it holds no role there that may sign in, and either its `MEMBER` there
was taken away or it holds another role there. An account holding just
`MEMBER` where `MEMBER` may not sign in (a members-only site) was never
invited and is not counted - or every ordinary account would be. While
migration 010 is pending, `blocked_in` is `[]` and `sign_in_roles` every role:
the users routes keep working in the minutes between an upload and Apply.

`language` on `POST` and `PUT` is lower-cased and trimmed; a language the IAM
does not offer is `422 invalid_language`, not turned into English. `POST`
without one makes an English account. On `PUT` the account's own current
language passes as no change, even when it is no longer offered, so renaming
somebody who still has a switched-off language works.

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
  roles: RoleGrant[];               // MEMBER included
  blocked_in: string[];             // client ids, as above
  last_login_at: string | null;
  created_at: string;
  updated_at: string | null;
}

interface FilterApplication {
  client_id: string;
  name: string;
  system: boolean;                  // the IAM's own entry: never asked about roles at sign-in
  roles: string[];
  sign_in_roles: string[];          // the roles whose holders may sign in
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
| `POST /api/admin/applications` | `ApplicationInput` → `201 { application }` |
| `PUT /api/admin/applications/{id}` | `ApplicationInput` (partial) → `AdminApplication` |
| `DELETE /api/admin/applications/{id}` | refused for `iam`; ends every token issued to it |
| `GET /api/admin/applications/{id}/members` | `{ members: { user_id, username, email, roles: string[] }[], blocked: { user_id, username, email }[] }`; 404 for no such application |

`members` are the accounts holding a role there beyond `MEMBER`, each with
every role it holds there (`MEMBER` included); `blocked` the accounts that are
switched on and blocked from it, by `blocked_in`'s rule (always `[]` for
`iam`, whose gate is Settings → Signing in).

All six answer `503 sign_in_rules_unavailable` ("Apply the pending migrations
in the admin panel") while migration 010 is pending; the rest of the panel
answers as usual.

```ts
interface AdminApplication {
  id: number;
  client_id: string;               // [a-z0-9-], 2-64, fixed after creation
  name: string;
  description: string | null;
  home_url: string | null;
  logo_url: string | null;
  enabled: boolean;
  login_required: boolean;         // the site sends everybody to sign in; off: it lets visitors in. Always true for `iam`
  access_token_ttl: number;        // seconds, 60-3600
  redirect_uris: string[];
  post_logout_redirect_uris: string[];
  web_origins: string[];           // allowed to call the token endpoint from a browser
  // Fixed (migration 011): ADMIN, EDITOR and MEMBER - `iam` ADMIN and MEMBER -
  // plus any role made before 011, kept read-only. MEMBER is held by every
  // account (migration 010). may_sign_in: holding this role lets an account
  // sign in to the site; one holding none that may is refused (access_denied).
  // Meaningless for `iam`.
  roles: { name: string; description: string | null; may_sign_in: boolean; members: number }[];
  system: boolean;                 // the IAM itself: cannot be deleted or disabled
  created_at: string;
  updated_at: string | null;
}
```

Every application is a public client and there are no landing page settings
(migration 011): the shape carries no `type`, secret, `show_on_landing` or
`sort_order`. A new application goes last (`sort_order` the largest + 1) and
gets ADMIN ("Everything in the site's admin panel"), EDITOR ("Edits the site's
content in its admin panel") and MEMBER ("Uses the site") from the database,
MEMBER for every account, its ADMIN and EDITOR for nobody.

`ApplicationInput` is the writable subset: `client_id` (create only), `name`,
`description`, `home_url`, `logo_url`, `enabled`, `login_required` (a boolean;
refused for `iam`, whose pages answer to Settings → Signing in),
`access_token_ttl`, `redirect_uris`, `post_logout_redirect_uris`, `web_origins`,
`roles`. A `type` other than `public` is refused ("Every application is a
public client: it names itself and proves each sign-in with PKCE - there are
no client secrets"); `type: 'public'`, `show_on_landing`, `sort_order` and an
`access` (from an older panel or script) are ignored.

`roles` is `{ name, description?, may_sign_in? }[]`: the roles are fixed, and a
save changes only whether each may sign in. On an update it names every role
the application has (any case); a role left out is refused ("The roles are
fixed - EDITOR cannot be removed; ..."; for MEMBER: "Every application keeps
its MEMBER role - take it from people instead"), as is a role it does not have
(adding or renaming one) and a `description` other than the role's own. On a
create it may name only the roles whose tick it sets. `may_sign_in` left out
keeps what the role has; for `iam` it is ignored. A site switched on must keep at least one role that may sign in
("At least one role must be allowed to sign in - switch the application off
instead"); one switched off may be saved without. Every refusal is
`422 invalid_application` with the sentence in `error`. A change of the roles
that may sign in, or of `login_required`, writes its own audit entry,
`application.sign_in_changed`, whose `details` hold
`{ sign_in_roles?: { before, after }, login_required?: { before, after } }`.
A new application's `application.created` entry holds what it starts with,
`{ login_required, sign_in_roles }` (the roles that may sign in, by name).

### Languages and strings

The IAM's own words (`api/translations.php`): the languages it offers and the
strings of its pages and its three mails. Keys are the code's - migrations
write them, these routes only change strings. English is what every other
language falls back to, key by key.

All eight answer `503 translations_unavailable` until migration 006 is applied
(from this same panel, Migrations). Their bodies are read as parsed: no body,
or a JSON list where an object belongs, is a refusal, not an empty object.

| Method + path | |
| --- | --- |
| `GET /api/admin/languages` | `{ languages: AdminLanguage[] }` - every language, the switched-off ones too, in order |
| `POST /api/admin/languages` | `{ code, name, native_name, enabled? }` → `201 { language: AdminLanguage }`, placed after the others. `enabled` left out means offered at once; the panel always sends it (a new language starts hidden there). |
| `PUT /api/admin/languages/{code}` | `{ name?, native_name?, enabled?, sort_order? }` - what is left out stays → `{ language: AdminLanguage }` |
| `DELETE /api/admin/languages/{code}` | the language and its strings go; its accounts go back to English (the foreign key's `ON DELETE SET DEFAULT`) → `{ accounts }`, how many |
| `GET /api/admin/translations` | `TranslationGrid` - every key, the `mail.` ones among them, with every language's string |
| `PUT /api/admin/translations` | `{ values: { key: { language: text } } }`, only what changed → `TranslationGrid` |
| `GET /api/admin/translations/{code}/export` | the language's own strings as a flat `{ key: text }`, not merged with English, `mail.` keys included; `{}` when it has none |
| `PUT /api/admin/translations/{code}/import` | a flat `{ key: text }` → `TranslationGrid`. The same checks as a save; an empty string is a key not translated yet and is passed over, so an import deletes nothing |

```ts
interface AdminLanguage {
  code: string;          // xx or xx-yy, small letters; fixed once made
  name: string;          // in English: "Slovak"
  native_name: string;   // as its speakers write it: "Slovenčina"
  enabled: boolean;      // offered: on the switcher, in discovery, a language an account can have
  sort_order: number;    // 0-1000, lowest first
  seeded: boolean;       // the migrations write strings for it (en, sk): cannot be deleted
  accounts: number;      // how many accounts have it
}

interface TranslationGrid {
  languages: AdminLanguage[];
  keys: { name: string; description: string; values: Record<string, string> }[];  // by name; description '' when none
}
```

A save is checked whole before anything is written, and written in one
transaction or not at all. A string sent as it already is stays as it was,
with who wrote it and when; of two people saving the same string, the last
save is kept. A blank string in another language deletes that language's
string (the key then reads in English). Blank is whatever has nothing to read:
spaces, or only characters of no width. Control characters other than tabs and
line breaks are dropped, and spaces around a string are trimmed.

Refusals of the language routes:

| Status | `code` | |
| --- | --- | --- |
| 404 | `not_found` | no such language, or not a code at all |
| 422 | `invalid_language` | a code not `xx` / `xx-yy`; a name that is not one line of 1-50 characters; `enabled` not a boolean; `sort_order` not a whole number from 0 to 1000; a body that is not an object |
| 409 | `language_exists` | `POST` of a code there is already |
| 422 | `too_many_languages` | the IAM keeps at most 20 |
| 409 | `fallback_language` | switching English off, or deleting it |
| 409 | `seeded_language` | deleting a language the migrations write strings for (Slovak): switch it off instead |

Refusals of a save and an import - `413 too_large` for a body over 1 MB, and
otherwise `422`, the first thing wrong, with `key` and `language` beside `code`
and `error` (and `placeholder` where it is about one, `keys` for unknown keys):

| `code` | |
| --- | --- |
| `invalid_value` | not `{ key: { language: text } }` (an import: not a flat object); a value that is not text; a page's string over 2,000 characters; a mail's string with a line break or a tab, a mail subject over 150 characters or another mail string over 400; a mail string of more bytes than one line of mail may have - 900, less 400 for every `{username}` in a greeting (the longest name there can be, kept for each: 500 with one, 100 with two, none with three) |
| `unknown_key` | a key the IAM does not have, empty or not (with `keys`: every such key sent) |
| `unknown_language` | a language the IAM does not have |
| `fallback_required` | a blank English string: every other language falls back to it |
| `invalid_placeholder` | a `{` or `}` that is not part of a `{placeholder}`, in any string: `{ count }`, `{počet}`, `{count`, `{{count}}` |
| `placeholder_changed` | an English page string whose placeholders are not exactly the stored English string's |
| `unknown_placeholder` | a page string in another language with a placeholder its English string does not have; a mail string, in any language, with one the code does not fill for that key |
| `placeholder_missing` | `login.continueTo` or `register.forApp` without `{app}`; a mail string, in any language, without a placeholder the code fills for that key |
| `address_in_mail` | a mail string with something that reads as an address - `://`, `www.` or `@`. A mail's only link is the one the server puts in. |

A mail's placeholders are the code's (`MAIL_KEYS` in `api/mail.php`), not the
stored English: greetings take `{username}`, validity lines `{hours}` or
`{minutes}`, the sign-in code's subject `{code}`. There is no placeholder for a
mail's link or code - that line is never part of a string.

The audit log records `language.created` (`name`, `native_name`, `enabled`),
`language.updated` (what changed), `language.deleted` (`name`, `accounts`,
`strings`), `translations.saved` and `translations.imported` (the languages,
how many strings changed, the first hundred keys, and every changed `mail.`
string's old and new text whole).

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

`GET /api/applications` - every application that is switched on, but the IAM
itself, in the order they were added (`sort_order`, then name; migration 011
took the landing page settings away, so `show_on_landing` is not read):
`[{ client_id, name, description, home_url, logo_url }]`. CORS open,
cacheable. The anotoki.eu landing page keeps its own list.

`GET /api/public/applications/{client_id}` - whether a site requires signing
in, for the sites' backends: under OpenID Connect above.
