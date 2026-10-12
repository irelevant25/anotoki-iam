# anotoki IAM - HTTP API

The contract between the IAM backend (`backend/`), its own frontend (`frontend/`) and
every application that signs people in through it. Two halves:

- **OpenID Connect** - the standard endpoints an application uses: discovery,
  keys, authorize, token, userinfo, logout, revoke. Anything that speaks OIDC
  can use them; the SDKs in `sdk/` are the small first-party clients. Beside
  them, anotoki's own `POST /api/oidc/preferences`, through which a site saves
  its signed-in reader's theme, dates, clock and language, and the two public
  answers a site's backend reads: whether it requires signing in, and which
  accounts were deleted.
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
- **Before the installer has run** - and whenever a `config/*.local.php` is
  missing or wrong - every endpoint but `/api/site-status` (below) -
  discovery and the protocol endpoints included, whatever the method - answers
  503 and does nothing else, in the anotoki family's words and shape (the gate
  of the family's library, anotoki/lib, which every anotoki site answers with;
  `Cache-Control: no-store`):

  ```json
  { "code": "not_set_up", "installed": false, "message": "This site is not set up yet. Its setup page connects it to its database.", "setup": "/setup.php" }
  ```

  `setup`, the installer's path, is there only while the IAM was never
  installed: `installed` is false until a real host's install has finished
  (`storage/installed.lock`, which it writes - on a development machine it
  never is). An IAM installed once never names the installer again, which has
  deleted itself:
  `{ "code": "not_set_up", "installed": true, "message": "The site is not available right now. Try again in a few minutes." }`.
  The IAM's pages show their not-set-up page for either; an application sees
  a failed sign-in.

### The session cookie

The IAM frontend is signed in by one cookie: `__Host-anotoki_iam` wherever it
is `Secure` (over HTTPS, or `cookie_secure` on), plain `anotoki_iam` on a
plain-http development machine. `HttpOnly`, `SameSite=Lax`, `Path=/`, never a
`Domain`. Its value is a random secret; the database keeps only its SHA-256. A
"keep me signed in" session sets `Max-Age` (to the session's absolute end: 30
days of inactivity, 90 days at most); without it the cookie lives until the
browser closes and the session dies after a day of inactivity (a week at most).

The `__Host-` prefix (a hardening of 2026-10-07, [decisions.md](decisions.md)):
a browser keeps a cookie of exactly that name only when the IAM's own host
sets it. The IAM reads the session cookie by that exact name, as the `Cookie`
header carries it - never a name that only decodes to it
(`__%48ost-anotoki_iam`, which a sibling host can set for the whole domain) -
and by that name alone: on HTTPS a cookie under the bare `anotoki_iam` is never
read (the owner's decision of 2026-10-10), beside a `__Host-` one or without
it. Even carrying a live session's secret it opens nothing - no page, no
code (`prompt=none` answers `login_required`), no sign-out - so one a sibling
host plants, with a sign-in hint beside it or not, signs nobody in. Either
name sent twice is no session.

A browser from before the prefix, which holds its session under the bare
name, is signed out at the IAM: the person signs in there once more (as of
2026-10-11 that is the owner alone - the sites are not public yet). The
sites are not affected - they hold their own tokens, and their refreshes use
no cookie. Every answer that uses the session - the `/api/account` and
`/api/admin` endpoints, `GET /api/oidc/authorize`, every sign-in - expires a
bare `anotoki_iam` sent over HTTPS (`Path=/`, this host, as it was set), and
sets nothing in its place; every sign-out and every refusal that drops the
cookie expires both names.

Applications never see this cookie. They get tokens.

### The device cookie

Every sign-in that opens a session (password, emailed code, Google, a
confirmation or reset link - each past two-factor) also sets
`anotoki_iam_device` (migration 016): `HttpOnly`, `SameSite=Lax`, `Secure`
over HTTPS, `Path=/`, `Max-Age=34560000` (400 days), set again at every
sign-in so a browser in use keeps it. Its value is a random secret; the
database keeps the SHA-256 of the account's id and that secret, so one
browser is a different row for every account signed in from it.

It opens nothing: it is not a session, not the two-factor remembered device
(`device_token`), and no endpoint reads it but the sign-in. It says only
whether the account has signed in from this browser before. When it has
not, and the account has signed in from another browser already, the
account's address is mailed "New sign-in to your anotoki account" - when (in
UTC), the browser and its system in a few words from a fixed list ("Firefox ·
Windows"; never the user agent as sent), and the account's Sessions page -
at most five such mails an hour (counted by the browsers mailed about, under
the account's lock). An account's first browser - every
account's first sign-in once 016 is applied, and a new account's - is noted
without a mail. A site's single sign-on and its refreshes open no session
and mail nothing. A password reset, and the owner of an address claiming an
account never confirmed, forget every browser the account knew (the browser
that does it is then its first). Before migration 016 no such cookie is set.

### The sign-in hint cookie

Every session opened here (each way in above, past two-factor) also sets
`anotoki_signed_in`, for the anotoki sites: a site that lets visitors in reads
it to decide whether to sign the person in with one silent hop (the owner's
decision of 2026-10-07, [decisions.md](decisions.md); what a site does with
it: [integrating-an-app.md](integrating-an-app.md)). It says only "somebody
signed in at the IAM in this browser". Its value is 16 random bytes in
base64url (22 characters), new for every session, kept nowhere and derived
from nothing - not the session's secret, its hash or `sid`, not the account.
No endpoint reads it as proof of anything: the IAM only looks at whether it
was sent, to clear it or to set it again.

| Attribute | |
| --- | --- |
| `Path` | `/` |
| `HttpOnly` | **no** - the sites' pages read it with `document.cookie`. It is the only IAM cookie a script can read |
| `SameSite` | `Lax` |
| `Secure` | exactly when the session cookie is |
| `Domain` | on a real host, the parent of the issuer's host - `anotoki.eu` for `iam.anotoki.eu` - so every site under it reads it; a host of two labels or an address keeps it host-only. `hint_cookie_domain` in `config/iam.local.php` names another domain the issuer's host is under; anything else there is passed over. Either way, only while some application switched on (not the IAM's own entry) signs in under that domain - one of its login redirect URIs at a host under it; else host-only. A setting passed over, or a domain no site is under, is said in the error log at most once a day. On a development machine (a loopback issuer) none: host-only `localhost`, which every port reads |
| `Max-Age` | the session's: none (until the browser closes) when it is not remembered, else the seconds left until the session's absolute end - up to 90 days |

`'hint_cookie_name' => null` in `config/iam.local.php` switches the hint off.

| When | Where | The hint |
| --- | --- | --- |
| A session opened | every way in | set, a new value - not for an account no site lets in (switched off, waiting for approval, its deletion pending, deleted - read afresh), where a hint left from the session it replaced is cleared |
| A live session, and no hint sent | a site's single sign-on (`GET /api/oidc/authorize`), every `/api/account` and `/api/admin` request whose session is still live when it is answered | set - a session from before the hint, or a browser that lost it |
| The sites are to try again | `DELETE /api/account/deletion` (cancelled); `PUT /api/account/password` and `POST /api/account/password/set` when they end a forced change of password | set, a new value |
| Signed out | `POST /api/logout`; `GET /api/oidc/logout` with a proven ID token, or with no session here; `DELETE /api/account/sessions/{id}` and `DELETE /api/admin/sessions/{id}` naming this browser's own session | cleared |
| A deletion asked for | `POST /api/account/deletion` | cleared: no site lets the account in now |
| An account no site lets in | the account and admin pages, and `GET /api/oidc/authorize` - `interaction_required` included - while the account's deletion is pending | cleared, when sent: a hint that raced the deletion |
| No live session behind the hint | the account and admin pages' `401 no_session`; `POST /api/login/request/{id}/accept`'s `401 no_session`; `GET /api/oidc/authorize` answering `prompt=none` with `login_required`, or sending the browser to `/login` | cleared, when sent - the first site that tries cleans a stale hint up for every site |
| A session this site may not use | `prompt=none` answered `interaction_required` (a role that may not sign in, a forced change of password, `max_age`) | kept: the session is good for other sites |

A session ended elsewhere, expired, or whose account was switched off leaves
the hint stale until that browser next reaches the IAM - at most one wasted
silent sign-in, by the first site that tries. Clearing it expires the copy
under the `Domain` the issuer names (whether or not a site is under it now)
and a host-only copy too. "Sent" means a value of the hint's shape
(`^[A-Za-z0-9_-]{16,64}$`, as the sites read it): anything else under its name
counts as none, so a live session puts a real one in its place. The session
cookie stays host-only and `HttpOnly` (`__Host-` on HTTPS, above), and never
gets a `Domain`; the hint brings no CSRF or CORS exemption with it.

### CSRF

Every first-party request that is not a GET must carry the header
`X-IAM-Request: 1`. A browser will not add a custom header to a cross-origin
request without a CORS preflight, and the first-party endpoints answer no
preflight, so a page on another origin cannot make one. Requests whose `Origin`
header is present and is not the issuer's own origin are refused as well.
Missing either: `403 { code: "csrf" }`.

The OIDC endpoints are exempt: authorize and logout are top-level navigations
(GETs), and token/userinfo/revoke/preferences are called cross-origin by
applications, carrying no cookie. Exactly those four paths are exempt, compared
with the path as the router sees it - percent-decoded - so `/%61pi/...` is no
way around the guard; every other write anywhere needs the header. None of the
four reads the session cookie, and none may ever: the header is what stands
between the cookie and a page on a sibling subdomain, which a browser counts
as the same site.

### Where a request comes from

What the audit log keeps (`ip`), a session shows and the limiter counts is the
requester's address by the anotoki family's one rule (the owner's decision of
2026-10-11, [decisions.md](decisions.md); `requestIp()`) - the limiter by its
bucket, an IPv4 address whole and an IPv6 address by its /64, which is also
what a sign-in attempt keeps as its `ip` (`2001:db8:aa:bb::/64`): the
connection's own - or, on a connection from one of Cloudflare's published ranges (the
trusted proxies, `config/iam.php`), the visitor's address Cloudflare names in
`CF-Connecting-IP`. From anywhere else that header is never believed, nor a
header that is no single address. It is the one header read, pinned in the
code: `X-Real-IP`, `True-Client-IP`, `X-Forwarded-For` and their kin - which
Cloudflare passes on as the visitor wrote them - never are, whoever sends
them. A configured range wider than an IPv4 `/8` or an IPv6 `/16` (`0.0.0.0/0`,
`::/0`) is refused, never believed. An IPv4 address written as IPv6
(`::ffff:203.0.113.9`) is the IPv4 one. The error log keeps none of it: a keyed
hash of the address's bucket (an IPv6 address its /64) - a pseudonym while its
key lasts -, as the pages' own errors are counted (`POST /api/client-errors`).

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
                       "preferred_username", "email", "email_verified", "locale", "preferences", "roles"],
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
4. Signed in already, the sign-in satisfies `prompt`/`max_age`, but the
   account's deletion is pending (`POST /api/account/deletion`, below): no
   code, and nothing goes back to the application. The request is spent and
   the browser goes to `{issuer}/error?code=account_deletion_pending`, the
   IAM's own page, which says so in the person's words and leads to the
   account page, where the deletion is cancelled. Not for `prompt=none`
   (step 5), which never shows a page; and a request that asks for
   credentials again (`prompt=login`, an older sign-in than `max_age`) goes to
   the sign-in page as usual, where accept refuses this account the same way.
5. `prompt=none` and step 3 did not apply: back with `?error=login_required`
   when nobody is signed in here, `?error=interaction_required` when somebody
   is (or `consent_required` never - first-party applications need no consent).
6. Otherwise the request is stored for 30 minutes and the browser goes to
   `{issuer}/login?request={id}` (or `/register?request={id}` for
   `prompt=create`), with `&lang={code}` when `ui_locales` named an offered
   language. The IAM
   frontend finishes it with `POST /api/login/request/{id}/accept`.

The sign-in hint (above): step 3 sets it when the browser sent none; steps 5
and 6 clear one the browser sent when nobody is signed in here - a
`login_required` answer among them; `interaction_required` keeps it, but for
an account whose deletion is pending (step 4), which has none.

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
has ended, the account is disabled, its deletion is asked for (which revokes
every refresh and personal token of the account at once), or the account may
no longer use the application; the application should then treat the person
as signed out. A code given before the deletion was asked for is refused the
same way at its exchange.

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
  "locale": "en",
  "preferences": { "theme": "auto", "date_format": null, "time_format": null }
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

`preferences` (with the `profile` scope, in both tokens and in userinfo, once
migration 015 is applied) is how the account wants every anotoki site to look:

```ts
{
  theme: 'auto' | 'light' | 'dark';                                 // auto: as the operating system says
  date_format: 'dmy_dot' | 'dmy_slash' | 'mdy_slash' | 'ymd_dash' | null;   // 6.10.2026, 06/10/2026, 10/06/2026, 2026-10-06
  time_format: '24' | '12' | null;                                  // 14:30, 2:30 PM
}
```

`null` is "automatic": the page language's own way. A site follows these for a
signed-in person in place of its own settings, and keeps its own when the
claim is absent (an IAM before 015, a sign-in without `profile`). A token
carries them as they were when it was minted; userinfo reads them fresh, and
`POST /api/oidc/preferences` answers them.

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
  "preferences": { "theme": "auto", "date_format": null, "time_format": null },
  "roles": ["ADMIN"]
}
```

`amr` is how the sign-in was made: `pwd` (password), `otp` (an authenticator
code), `email` (an emailed code or link), `google`.

### `GET|POST /api/oidc/userinfo`

`Authorization: Bearer <access token>`. Answered cross-origin to any origin some
application registered - a read, so not held to the token's own application's
origins as the token endpoint is. Answers
the claims above for the token's user - read fresh, so a changed username shows
here before the next token. `401` with `WWW-Authenticate: Bearer error="invalid_token"`
when the token is not good, its session has ended, or its account is switched
off, waiting for approval or being deleted.

### `POST /api/oidc/preferences`

A site saves what its signed-in reader chose with the site's own controls -
its theme toggle, its language switcher - to the account, and every site
follows it (the JS SDK's `savePreferences()`). `Authorization: Bearer <access
token>` from a sign-in - not one a script's personal token was exchanged for -
carrying the `profile` scope. CORS as for the token endpoint: answered to an
origin some application registered, and refused for an `Origin` that is not
the token's own application's (no `Origin` at all - the site's backend - is
the token's own business). No cookie is read, and no CSRF header is needed.
The person must still be let into that site, by the rules a refresh keeps.

`{ theme?, date_format?, time_format?, language? }` as a JSON object (a form
body is read the same way), at least one:
`theme` is `auto`, `light` or `dark`; `date_format` one of the four orders
above or `null`; `time_format` `"24"`, `"12"` or `null` (`"auto"` is taken for
`null` too); `language` a code the IAM offers (discovery's
`ui_locales_supported`). Changes only those, on the
token's own account, and answers the account as it now stands:

```json
{ "locale": "sk", "preferences": { "theme": "dark", "date_format": "ymd_dash", "time_format": null } }
```

The tokens carry the change from their next refresh.

Errors (OAuth's shape): `401 invalid_token` as for userinfo (an account
being deleted included); `400
invalid_request` for an `Origin` that is not the token's application's, and for
a body that is not an object, is empty, or names anything else or a value not
on offer; `403 access_denied` for a personal token's access token, and for a
person the site no longer lets in (the site switched off, their roles there
keeping them out, the address unconfirmed); `403 insufficient_scope` for a
token without `profile`; `503 temporarily_unavailable` while migration 015 is
pending.

### `GET /api/oidc/logout`

RP-initiated logout, a top-level navigation.

| Parameter | |
| --- | --- |
| `client_id` | the application asking |
| `id_token_hint` | the ID token it holds; when it verifies and names the current session, the sign-out happens without asking |
| `post_logout_redirect_uri` | must be one of the application's registered logout redirect URIs |
| `state` | echoed back |

Ends the IAM session, spends every refresh token issued under it (in every
application), and clears the cookie and the sign-in hint; then redirects to
`post_logout_redirect_uri?state=...`. Without a verifiable `id_token_hint` the
browser is sent to `{issuer}/logout?request=...` to confirm first, so a link on
somebody else's page cannot sign people out; the hint stays until the page's
`POST /api/logout` confirms. With nobody signed in here it goes straight to
the landing page, clearing a sign-in hint the browser still carries.

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

### `GET /api/public/anonymized-accounts`

The accounts deleted at their owners' request (migration 016; "Deleting the
account" below), for the sites' **backends**: each site scrubs its own copies
of the person's name and address under those ids - `preferred_username`,
`email`, anything else that says who it was - and keeps the rest of its data,
nobody's now. The ids are never anybody's again: the IAM keeps the row, a
placeholder. No session, no token, no CSRF header; in no CORS list (a
browser never needs it).

| Parameter | |
| --- | --- |
| `since` | optional: an ISO-8601 time from `1970-01-01T00:00:00Z` on - `2026-11-06T10:00:00Z`, a fraction of a second and an offset allowed (send `+` as `%2B`), or a date alone (midnight UTC). The accounts anonymized at or after it - **inclusive**. Left out: from the beginning |
| `after` | optional, only with `since`, and only as `next` gives it: an account id. Together they say where the next answer of the same paging run starts: anonymized after `since`, or at `since` with an id above `after` |

```json
{
  "accounts": [
    { "sub": "123", "anonymized_at": "2026-11-06T10:00:00Z" },
    { "sub": "130", "anonymized_at": "2026-11-06T10:00:00Z" }
  ],
  "next": { "since": "2026-11-06T10:00:00Z", "after": "130" }
}
```

- `accounts`: in the order they went - by `anonymized_at`, then by id - at
  most 500. `sub` is the id as the tokens carry it (a decimal string);
  `anonymized_at` is whole seconds, UTC. These two fields and nothing else.
- `next`: when there may be more, the `since` and `after` of the next
  answer - ask `?since={next.since}&after={next.after}` until it is `null`.
  It is for one paging run, straight after the answer it came with, and is
  not kept: times are whole seconds, and an account anonymized later in the
  same second as `next.since` may have a lower id than `next.after`.
- How a site keeps up between runs: remember the newest `anonymized_at`
  handled, and ask from it next time with `since` alone. `since` is inclusive
  on purpose - the ids of that second come again, and one seen twice costs
  nothing - so going back a day is harmless, and is the safe way to start.
- `Cache-Control: public, max-age=60`. `400 invalid_request` for a `since`
  that is not such a time, is before 1970-01-01 UTC (`0000-01-01` included),
  or is a date that does not exist (`2026-02-30`), and for an `after` that is
  not an id or comes without `since` (`no-store`).
  Before migration 016 every answer is `{ "accounts": [], "next": null }`:
  nobody was deleted then. `503 not_set_up` before the installer.

What it gives away: that an id was deleted, and when - to anybody who asks.
Nothing about who it was: the IAM itself no longer knows.

The request also runs housekeeping (the host has no cron), so the sites
asking is itself part of what keeps the deletions on time.

---

## First-party: signing in

None of these need a session. Every non-GET needs `X-IAM-Request: 1`.

### `GET /api/public/config`

What the sign-in pages need before they can draw themselves.

```json
{
  "login_enabled": true,
  "registration_enabled": true,
  "registration_approval": false,
  "registration_news": true,
  "google_enabled": true,
  "google_client_id": "105...apps.googleusercontent.com",
  "password_min": 8,
  "languages": ["en", "sk"]
}
```

`registration_approval` is Settings → New accounts need approval: while it is
on, an account made by registering or signing up with Google waits for an
administrator's approval before it can sign in anywhere (the register page
says so above its form). `false` before migration 012, and while the database
cannot be reached.

`registration_news` says the register page offers the news from the sites (the
announcements) in a box of its own, unticked - `false` before migration 013,
and while the database cannot be reached.

`languages` is the codes on offer, in the admin panel's order - the same list
as discovery's `ui_locales_supported`, and like it `["en", "sk"]` before
migration 006 or while the database cannot be reached. The pages give a new
account the reader's language settled against this list, even while they are
still showing the compiled English.

### `GET /api/site-status`

Where the IAM stands, in the anotoki family's words - every anotoki site
answers this path the same way (anotoki/lib):

```json
{ "state": "update_pending", "installed": false }
```

These two fields and nothing else; `Cache-Control: no-store`, no cookie read,
in neither CORS list. It is the one path that answers in every state, before
the installer has run too: the pages ask it to know what to show. It is
public, as on every anotoki site: anybody may learn that an update waits or
that the database does not answer - a timing signal, which opens nothing
(`docs/decisions.md`).

| `state` | |
| --- | --- |
| `ready` | everything set up and applied |
| `update_pending` | a file of `migrations/iam` waits to be applied. The IAM goes on answering everything meanwhile - unlike every other anotoki site, it does not block while an update waits: it is the door they all sign in through, and its administrator signs in through it to press Apply. Its features check their own tables, as ever. The admin panel's line "A database update is waiting" reads this |
| `not_set_up` | a `config/*.local.php` is missing or wrong: every other path answers `503 not_set_up` (Conventions, above) |
| `unavailable` | set up, but the database does not answer; the other paths answer as they would without it |

`installed` is whether a real host's install has finished
(`storage/installed.lock`); once it is true, nothing offers the installer.

### `GET /api/translations/{code}`

The pages' words in one language. The same for everybody: no cookie is read or
set, a page whose session has ended still reads in its language, and it is in
neither CORS list - only the IAM's own pages read it. Nothing else lives under
`/api/translations` (`/api/translations` alone is a 404, a write a 405). It is
the anotoki family's route (anotoki-lib's `TranslationsRoutes`, on the IAM's
configuration in `api/translations.php`): this answer is every anotoki site's
bundle, byte for byte.

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
- `values` is every key a page reads: the language's own strings with English
  under them, so a key it has no string for reads in English. Among them are
  the anotoki library's own keys, `anotoki.*` - the words of its UI kit, its
  language and theme switches and its status page (`anotoki.language.label`,
  `anotoki.ui.close`, `anotoki.siteStatus.updatingTitle`, ...), written by the
  library's migrations and reworded on the Translations page like the rest.
- `english` is English's own strings, for the admin panel, which is English
  whatever the reader chose. Left out when `language` is `en`.
- No `mail.` key is in either map: the mails' words are the server's.

`404 not_found` for a code that is not `xx` or `xx-yy` in small letters (`EN`,
`en-GB`, `en_gb` are no codes), before any query. `503 translations_unavailable`,
with `Retry-After: 60`, while migration 006 is pending, while the database
cannot be reached, or when English has no strings at all: not `not_set_up`,
which would send the pages to their not-set-up page - they keep the English
compiled into the build meanwhile.

Caching: `ETag` (an MD5 of the body, in quotes) and `Cache-Control: no-cache`,
so the browser asks every time and is told `304` (empty, with the same two
headers) when nothing changed. `If-None-Match` is compared the weak way: any
tag of its list, or `*`, with or without `W/` in front, and with or without
`-gzip`, `-br` or `-zstd` just inside the closing quote - what a compressing
Apache (mod_deflate, mod_brotli) or proxy does to the tag on the way out.
Without that, behind such a server no browser would ever get the 304.

### `POST /api/client-errors`

An error a page met, for the error log (the owner's decision of 2026-10-11:
every anotoki site's error log holds its pages' errors too). The anotoki
family's route (anotoki-lib 0.5.0's `ErrorLogRoutes::client()`); the pages
send it through `@anotoki/lib/error-report` - after the error is handled,
scrubbed, each error once, five a page load at most.

```json
{ "type": "TypeError", "message": "Cannot read properties of undefined", "page": "/account/security", "stack": ["at render (https://iam.example/chunk-AB12.js:3:4)"] }
```

`204`, written into the error log as `source: "client"` (below, "Error
log"): `type` (an identifier, else `Error`), `message`, `page` (a path, its
segments that could be a secret or a person's replaced), up to 12 frames of
`stack` (a list or one text) and `status` (a number, optional) - every text
redacted as every line is: the library's rules, the IAM's names of secrets,
and its own prefixes and cookies (`iam_rt_`, `iam_pat_`, its cookies' names)
taken out before the library reads them. Who sent it: the person signed in,
when this browser's session cookie names one (read only - nothing is
touched, set or cleared), as their id; never more of them, and of a visitor
nothing but a keyed hash of their address's bucket.

A first-party write: the CSRF header and the IAM's own `Origin` (`403 csrf`),
in neither CORS list; no session needed - a sign-in page fails too.
`415 unsupported_media_type` for anything but JSON, `413 too_large` past
8 KB, `400 invalid_json`, `422 message_required` for no message (or a body
that is no object). At most 20 an hour from one sender - a signed-in person
by their account, a visitor by their address (an IPv6 one by its /64) - and
500 a day from the visitors together, as many from the people signed in:
past it, `429 { code: "too_many", retry_after }` with `Retry-After`, and the
page stops reporting until it is loaded again. Requests to it are never
written into the log as failures of their own.

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
| `no_session` (401) | not signed in (a sign-in hint sent is cleared) | the sign-in form |
| `request_expired` (404) | too old, or used | "Back to {app}" (`home_url`) |
| `email_not_confirmed` | account not confirmed yet | resend the confirmation |
| `password_change_required` | an admin set this password | the change-password form, then accept again |
| `access_denied` | this account holds no role in this application that may sign in (its MEMBER taken away, or MEMBER not allowed to sign in there and no other role) | "Use another account" / "Back to {app}" |
| `account_deletion_pending` | the account's deletion is asked for (`POST /api/account/deletion`): nothing is issued to any site until it is cancelled | "Go to your account" (where it is cancelled) / "Use another account" / "Back to {app}" |
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
cookie is set - and the device cookie, which may mail the account about a new
browser, and the sign-in hint (both above); so on every way in below that
opens a session. Refusals:

| Status | `code` | |
| --- | --- | --- |
| 401 | `invalid_credentials` | wrong address or password (the same answer for both) |
| 403 | `password_login_unavailable` | this account signs in another way |
| 403 | `totp_required` | ask for the code, send again with `totp` |
| 403 | `totp_invalid` | the code was wrong |
| 403 | `email_not_confirmed` | offer to resend |
| 403 | `account_disabled` | an admin switched this account off |
| 403 | `approval_pending` | made by signing up while new accounts need approval, and not approved yet: no session until an administrator approves it, who mails the person then |
| 403 | `login_disabled` | signing in is switched off (IAM admins are exempt) |
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

`{ credential, totp?, device_token?, remember_device?, remember?, language?, news_mail? }` -
`credential` is the Google Identity Services id token. Signs in the account that
Google identity is attached to; attaches it to the account with the same verified
address; or makes a new account (`201`, `"created": true`) - with the news from
the sites only when `news_mail` is `true`: the register page's box, handed over
with the credential (from the sign-in page nobody was asked, and anything else
is no; for an account that is there already it changes nothing). Answer as
`/api/login/password` plus `created`. Refusals add `google_disabled` (403) and
`invalid_token` (400), and `429 too_many_attempts` from the same limiter as the
other ways in. A new account made while new accounts need approval is made,
waiting, with no session: `403 approval_pending` with `"created": true` beside
`code` and `error`.

Attaching to an account whose address was never confirmed first removes
everything its registrant set - password, two-factor, recovery codes,
remembered devices, sessions, tokens, other identities, a yes to the news,
the browsers it signed in from, and a deletion of the account it asked for
(cancelled, audited `account.deletion_cancelled` with `{ by: "address_claimed" }`) -
since whoever registered it need not own the address Google has just vouched
for. The same happens when a reset link reaches such an account's mailbox.

### `POST /api/register`

`{ username, email, password, language, request?, news_mail? }` → `201 { "email", "sent", "approval_pending" }`.
`news_mail` is the register page's box for the news from the sites, offered
unticked: `true` gives the account announcements, `false` or left out none
(`422 invalid_input` for anything but a boolean); a yes is kept in the audit
log's `account.created`.
No session: the account works once the emailed link is opened - and, when
`approval_pending` is true (Settings → New accounts need approval), once an
administrator has approved it as well. `request`, when
the registration started from an application, is remembered so the confirmation
can offer the way back. `409` for a taken username or address (named),
`422` for invalid input, `403 registration_disabled`, `429 too_many_attempts`.
`language` (here and on `POST /api/login/google`) is lower-cased and trimmed; one
the IAM does not offer makes an English account rather than a refusal.

### `POST /api/register/confirm`

`{ token }` from the emailed link → `{ "user": AccountUser, "continue_to": { "name", "url" } | null }`
and the session cookie is set. `400 invalid_token`. An account waiting for
approval has its address confirmed and the link spent all the same, and is
answered `403 approval_pending`, with no session.

### `POST /api/register/resend`

`{ email }` → always `{ "requested": true }`.

### `POST /api/password/forgot` and `POST /api/password/reset`

`{ email, request? }` → always `{ "requested": true }`.
`{ token, password, totp? }` → `{ "user": AccountUser, "continue_to": ... }`,
signs in, and takes the account back: every other session, every personal
token, every remembered device and every outside identity (an attached Google)
ends. A Google account under the same address attaches itself again at its next
sign-in. `400 invalid_token`, `422` for a short password. An account waiting
for approval gets its new password and `403 approval_pending`, with no session.

With two-factor on, the link alone is not enough - it proves the mailbox, which
is what the second factor stands in case of: `403 totp_required` until `totp`
(an authenticator or recovery code) comes too, `403 totp_invalid` for a wrong
one, `429` past the limiter. Nothing is spent or changed until it passes, so the
same form goes again with the code.

### `POST /api/logout`

`{ request? }` - ends the current session and every refresh token issued under
it, and clears the cookie and the sign-in hint. Answers `{ "message": "Signed out", "redirect": string | null }`,
whether or not there was a session. `request` is the id from a logout that
needed confirming (below); `redirect` is then where the application asked to
land (`post_logout_redirect_uri?state=...`), and the page navigates there.

### `GET /api/logout/request/{id}`

A logout an application asked for without a verifiable ID token, waiting on the
`/logout?request={id}` page for the person to confirm:
`{ "id", "application": { client_id, name, home_url, logo_url } }`, or
`404 { code: "request_expired" }`. Declining is simply not confirming - the page
offers "Stay signed in" with a link to the application's `home_url`.

### `POST /api/news/unsubscribe`

`{ token }` - the link at the end of every announcement (below, the admin
panel's Announcements) leads to `/unsubscribe?token=...`, which asks first and
then sends this: the account the token names gets no more announcements
(`news_mail` false - the account page's switch). No session is needed; the
token is the account id and a MAC of it under a key derived from the master
key for this one use (HKDF), never expires, and can do nothing else. Answers `{ "news_mail": false }`, the same once the
news is off already; `400 { code: "invalid_token" }` for a token that is not
the IAM's; `503 { code: "announcements_unavailable" }` while migration 013 is
pending. It needs the CSRF header like every first-party write - which is why
a mail program's one-click unsubscribe (RFC 8058) is not offered: the
`List-Unsubscribe` header of an announcement names the page.

---

## First-party: the account page

Every endpoint here needs the session cookie; without one, `401 { code: "no_session" }`
(and a sign-in hint the browser sent is cleared). With one, the answer sets
the sign-in hint when the browser sent none - not while the account's
deletion is pending.

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
  is_admin: boolean;              // holds ADMIN in the IAM itself - false while the account's deletion is pending
  news_mail: boolean | null;      // gets the announcements; null while migration 013 is pending
  preferences: {                  // what every anotoki site follows (the claim above); null while migration 015 is pending
    theme: 'auto' | 'light' | 'dark';
    date_format: 'dmy_dot' | 'dmy_slash' | 'mdy_slash' | 'ymd_dash' | null;
    time_format: '24' | '12' | null;
  } | null;
  deletion_due_at: string | null; // when the account is deleted, while its deletion is pending (below); null otherwise, and while migration 016 is pending
}
```

| Method + path | Body | Answer |
| --- | --- | --- |
| `GET /api/account` | | `AccountUser` |
| `PUT /api/account/profile` | `{ username?, language?, news_mail?, theme?, date_format?, time_format? }` | `AccountUser` (409 username taken; `422 invalid_language` for a language not on offer; `news_mail` is true or false - `422 invalid_input` otherwise, `503 announcements_unavailable` while 013 is pending; the three preferences take the values of `POST /api/oidc/preferences` - `422 invalid_preference` otherwise, `503 preferences_unavailable` while 015 is pending) |
| `PUT /api/account/password` | `{ current_password, new_password }` | `{ message }` - other sessions and remembered devices end; a forced change done sets a new sign-in hint (above), so the sites that met `interaction_required` try again; `422 wrong_password` for a wrong current password, each one counted by the sign-in limiter: `429 too_many_attempts` past it (ten from one address in 15 minutes), the right one included |
| `POST /api/account/password/set` | `{ password }` | `AccountUser` - first password on an account without one; also clears a forced change, and then sets a new sign-in hint. Asks for the proof below - for an account with no password, a sign-in of the last 15 minutes: `403 reauthentication_required` otherwise. `409 password_already_set`, `422 invalid_password` |
| `PUT /api/account/password/enabled` | `{ enabled, password? }` | `AccountUser` - asks for the proof below: `password`, the current one, to switch password sign-in off; a sign-in of the last 15 minutes to switch it on. `409 would_lock_account` / `no_password` first |
| `POST /api/account/google` | `{ credential, password? }` | `AccountUser` - asks for the proof below: `password`, the current one, while password sign-in is on; else a sign-in of the last 15 minutes. `400 invalid_token` for a credential that does not verify, `409` already connected elsewhere |
| `DELETE /api/account/google` | | `AccountUser` - `409 would_lock_account` |
| `POST /api/account/2fa/setup` | | `{ secret, uri }` (otpauth URI for the QR code) - a new secret each time, replacing one still waiting; nothing changes until enable confirms it. `409 totp_already_on` first; then, for an account whose password is not a way in, `403 reauthentication_required` while its sign-in is more than 15 minutes old - before a secret is made, since enable would refuse that sign-in (the proof below). An account whose password is a way in sets up from a sign-in of any age |
| `POST /api/account/2fa/enable` | `{ code, password? }` | `{ recovery_codes: string[] }` - other sessions end. `409 totp_already_on` / `totp_not_started` first; then the proof below, before the code is read: `password`, the current one, while password sign-in is on, else a sign-in of the last 15 minutes (`429 too_many_attempts`, `422 wrong_password`, `403 reauthentication_required`); then `400 totp_invalid` for a wrong code |
| `POST /api/account/2fa/disable` | `{ code }` | `AccountUser` |
| `POST /api/account/2fa/recovery` | `{ code }` | `{ recovery_codes }` |
| `DELETE /api/account/devices` | | `{ ended }` |
| `GET /api/account/sessions` | | `{ sessions: SessionEntry[], failed_since_last_login }` |
| `DELETE /api/account/sessions/{id}` | | `{ message }` - this browser's own session (the page offers Sign out instead) is answered as a sign-out: the session cookie and the sign-in hint cleared |
| `DELETE /api/account/sessions` | | `{ ended }` - all but this one |
| `GET /api/account/applications` | | `{ applications: AccountApplication[] }` |
| `GET /api/account/tokens` | | `{ tokens: PersonalToken[] }` |
| `POST /api/account/tokens` | `{ name, client_id, expires_in_days }` | `201 { token: "iam_pat_...", entry: PersonalToken }` - the token is shown this once |
| `DELETE /api/account/tokens/{id}` | | `{ message }` |
| `POST /api/account/deletion` | `{ password? }` | `AccountUser`, `deletion_due_at` set - the deletion is asked for (below) |
| `DELETE /api/account/deletion` | | `AccountUser`, `deletion_due_at` null - the deletion is cancelled (below) |

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

`SessionEntry.revoked_reason` is one of `signed_out`, `signed_out_elsewhere`,
`password_changed`, `security_change`, `replaced`, `ended_by_admin`,
`account_disabled`, `address_claimed`, `account_deletion` (ended when the
account's deletion was asked for from another browser) - or one a session
brought over from the genshin site kept (`migrated`, its own reasons), or
one a later version adds: a page shows a reason it does not know as "Ended".

### The proof a session alone cannot give

A session left open on somebody else's computer must not be enough to
delete the account - nor to make the proof the deletion asks for: give a
password-less account a password the session's holder chose, connect their own
Google to sign in afresh with, or switch password sign-in off so that a fresh
sign-in is all it takes - nor to lock the owner out, switching two-factor on
with the holder's own authenticator and keeping its recovery codes. So these
five ask for one proof, the same one (`accountProof()`):

| Endpoint | |
| --- | --- |
| `POST /api/account/deletion` | `{ password? }` |
| `POST /api/account/password/set` | (the account has no password: always the recent sign-in) |
| `PUT /api/account/password/enabled` | `{ enabled, password? }` |
| `POST /api/account/google` | `{ credential, password? }` |
| `POST /api/account/2fa/enable` | `{ code, password? }` |

Setting two-factor up (`POST /api/account/2fa/setup`) asks for no proof of its
own: it only keeps a secret waiting, which opens nothing until enable confirms
it. But an account with no password to ask for is turned away there already
with enable's `403 reauthentication_required` while its sign-in is more than 15
minutes old - before a secret is made, so that nobody scans a QR code enable
would then refuse (and the next setup replace). It reads no password and counts
nothing for the limiter. An account whose password is a way in sets up from a
sign-in of any age and gives the password at enable.

- An account whose password is a way in (`has_password` and
  `password_login_enabled`): `password`, its current password.
- Any other: a sign-in of the last 15 minutes - the session's own; a
  `password` sent is not read.

| Status | `code` | |
| --- | --- | --- |
| 422 | `wrong_password` | the password is wrong or missing; counted by the sign-in limiter, as a wrong password at the sign-in is |
| 403 | `reauthentication_required` | no password to ask for, and the session's sign-in is more than 15 minutes old: sign out and in again, then send it again within 15 minutes |
| 429 | `too_many_attempts` | the sign-in limiter (ten failures from one address against the account, or fifty from the address, in 15 minutes), before anything is read |

The deletion, the first password, the switch and two-factor check what they
are about first (their own `409` / `422`), then the proof - two-factor before
it reads the code; connecting Google asks for the proof first (after `403
google_disabled`), then reads the credential. The audit entry of each says
which proof it was (`details.proof`: `password` or `recent_sign_in`):
`account.deletion_requested`, `account.password_set`,
`account.password_login_on` / `_off`, `account.google_connected`,
`account.2fa_on`.

### Deleting the account

The owner's decision of 2026-10-07 (migration 016, `docs/decisions.md`): the
account is deleted thirty days after its owner asks, and can be kept until
then; the sites keep what was made on them, under no name. Only the account
itself asks and cancels - an administrator can do neither for anybody.

**`POST /api/account/deletion`** `{ password? }` - session and CSRF header,
as every write here. The proof is the one above ("The proof a session alone
cannot give"):

- an account with a password and password sign-in on (`has_password` and
  `password_login_enabled`): `password`, its current password;
- any other (Google only, or password sign-in switched off): a sign-in of the
  last 15 minutes - the session's own; a `password` sent is not read.

Nothing the session can change gives it that proof: a first password, Google
connected and password sign-in switched on or off ask for the same proof
themselves.

Then, in one transaction: `deletion_due_at` is thirty days from now; every
other session of the account ends (`revoked_reason: account_deletion`), and
every refresh and personal token is revoked - this session's included. The
account is closed at once: nothing is issued to any site again (authorize's
`account_deletion_pending` page, accept's `403 account_deletion_pending`, the
code exchange and every refresh `invalid_grant`, userinfo and
`POST /api/oidc/preferences` `401`, a new personal token `403
account_deletion_pending`), so every site loses the person within one access
token's lifetime; no announcement goes to it either (`left_out.deleting`).
The sign-in hint is cleared, and none is set for the account while the
deletion is pending. The IAM's own sign-in keeps working by every way in - that
is where the deletion is cancelled - but its admin panel does not (`403
account_deletion_pending`, and `is_admin` is false). The account's address is
mailed "Your anotoki account will be deleted" - the day, and the account page
- in its language, when the address is confirmed. Audited
`account.deletion_requested` (`{ due, proof: "password" | "recent_sign_in" }`).

| Status | `code` | |
| --- | --- | --- |
| 200 | | `AccountUser`, `deletion_due_at` set |
| 422 | `wrong_password` | the password is wrong or missing (an account that signs in with one); a counted attempt, as at the sign-in |
| 403 | `reauthentication_required` | no password to ask for, and the sign-in is more than 15 minutes old: sign out and in again, then ask within 15 minutes |
| 409 | `last_admin` | the account is the last administrator of the IAM: another must be made one first. An administrator whose own deletion is pending does not count |
| 409 | `deletion_already_requested` | it is asked for already |
| 429 | `too_many_attempts` | the sign-in limiter: wrong passwords here count, and each request (a mail) too |
| 503 | `account_deletion_unavailable` | migration 016 is pending |

**`DELETE /api/account/deletion`** - session and CSRF header. Until the
account is deleted (thirty days, give or take the hour housekeeping may take)
the deletion is cancelled: `deletion_due_at` back to null, audited
`account.deletion_cancelled`, and the address mailed "Your anotoki account
will not be deleted" - news its owner must get when it was not them. What the
request ended stays ended: the person signs in to the sites again, and a new
sign-in hint lets the sites that welcome visitors do it by themselves. `200
AccountUser`; `409 deletion_not_requested` when nothing is asked; `503
account_deletion_unavailable` while 016 is pending. Never limited.

**When it is due**, housekeeping deletes it (it runs on ordinary traffic, at
most an hour apart), in one transaction - and once that is committed the
address the account had is mailed "Your anotoki account is deleted": the last
mail, with no link, its words in the account's language read before the
commit, logged under the placeholder address and no account. Never for a
deletion that did not happen, and never twice. In the transaction:

- gone: its sessions, codes, refresh and personal tokens, remembered
  two-factor devices, the browsers it signed in from, one-time mailed tokens,
  recovery codes, outside identities (Google), every role, the sign-in
  attempts and email log rows of the account or its address, and sign-in
  requests that carried the address as a hint;
- scrubbed, the event kept: the audit log's rows by the account - and about
  it with nobody else acting - lose their IP, its rows about the account lose
  the names and addresses in their details, a personal token's name goes from
  its row; an announcement's row for the person keeps whether it went, not
  where;
- the row stays, so its id - every site's `sub` - is never anybody else's:
  username `deleted-{id}`, address `deleted-{id}@deleted.invalid` (a reserved
  domain that never delivers), no password or two-factor, English, `auto`,
  no news, unconfirmed, switched off, `anonymized_at` set. Audited
  `account.anonymized`, by nobody.

The address is free again then: it can make a new account, with an id of its
own. The admin panel shows such a row and changes nothing about it (`409
account_anonymized`). The sites learn the ids at
`GET /api/public/anonymized-accounts` (above).

A request that read the account a moment before it went, and writes after,
puts nothing back. The database keeps an anonymized row switched off,
unconfirmed, with no password, no password sign-in and no two-factor
(`users_anonymized_check`); every write to the row asks for one that is not
anonymized, and finds none - a reset or confirmation link answers `400
invalid_token`, an account page write `401 no_session`, an admin write `409
account_anonymized`, a site's save of preferences `401 invalid_token`; no
reset link or sign-in code is issued - one asked for while the anonymization
holds the account waits for it and then reads the row again - so none is
mailed. One issued a moment before the anonymization began is deleted with
the rest; its mail may still go, to the address the account had, with a link
or code that opens nothing. What such a request leaves elsewhere - a session,
a token, an identity, a role, a mail logged, an audit entry with the person's
IP or name - goes at the next housekeeping run.

---

## First-party: the admin panel

Every endpoint needs the session cookie **and** the `ADMIN` role in the
application `iam`; otherwise `401 no_session` / `403 { code: "forbidden" }`.
An administrator whose own account's deletion is pending administers nothing
until they cancel it: `403 { code: "account_deletion_pending" }` on every
endpoint here.

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
| `GET /api/admin/approvals` | `{ accounts: { user_id, username, email, email_confirmed, created_at }[], account_approval: boolean }` - the accounts waiting for approval and switched on, oldest first; `account_approval` is the setting |
| `POST /api/admin/users/{id}/approve` | → `{ account: AdminUser, mailed: boolean }` - lets an account that waits in |
| `POST /api/admin/users/{id}/decline` | → `{ account: AdminUser, mailed: false }` - switches an account that waits off |

Self-lockout rules carry over: an admin cannot disable themselves or take the
IAM `ADMIN` role from themselves, and the last IAM admin cannot be removed.
"The last" counts the administrators switched on and not on their way out -
one whose deletion is pending is not counted (nor in `stats.admins` and
`filters.admins`), so the one who stays can never be switched off or lose the
role, while the one leaving can.

**Deleted accounts** (migration 016): an account deleted at its owner's
request (the account page's "Deleting the account") stays as a row with
`anonymized_at` set, a placeholder name and address, no roles, switched off.
Every change to it is refused `409 account_anonymized` - `PUT
/api/admin/users/{id}`, `/password`, `/enabled`, `/roles`, `DELETE .../2fa`,
`approve`, `decline` - since switched on again or given a password it would be
somebody signing in as an id every site has made nobody's.
`deletion_due_at` is set while an account's own deletion is pending; nobody
can ask for or cancel it here.

**Approval of new accounts** (migration 012). While Settings → New accounts
need approval (`registration_approval`) is on, an account made by
`POST /api/register` or a Google sign-up has `approved: false` and signs in
nowhere - every way in answers `403 approval_pending` - until it is approved.
Accounts made here (`POST /api/admin/users`), by the installer,
`create-admin.php` or the import never wait. Approving mails the person the
sign-in page's link, in their language, when their address is confirmed
(`mailed: true`); one approved before that is signed in by its confirmation
link. Declining switches the account off - nothing is deleted, its address
stays taken, switching it on again puts it back among the waiting - and mails
nobody. `404 not_found` for no such account; `409 not_waiting` for one that is
not waiting, or is switched off (a declined account is switched on first, and
waits again); `409 not_confirmed` for declining one whose address is not
confirmed - whoever registered it may not own the address, and its owner
takes such an account back by a reset link or Google, which nothing does for
an account switched off. Declining keeps the last-admin rule, and
`PUT .../roles` refuses the IAM's `ADMIN` for an account that waits
(`422 not_approved`): it administers nothing until approved. All three answer
`503 sign_in_rules_unavailable` while migration 012 is pending. Switching the
setting off (`PUT /api/admin/settings`) approves everybody still waiting and
switched on, in the same transaction - not a declined account. The audit log
records `user.approved` and `user.declined`, `approvals.released`
(`{ accounts }`, how many the switch let in) and, on `account.created`,
`waits: true` for an account made waiting.

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
  approved: boolean;                // false: waiting for an administrator's approval (true while 012 is pending)
  last_login_at: string | null;
  created_at: string;
  updated_at: string | null;
  deletion_due_at: string | null;   // its owner asked for its deletion: when it is due; null otherwise (and while 016 is pending)
  anonymized_at: string | null;     // deleted then: the row is a placeholder, and changes no more; blocked_in and roles are []
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
  login_attempts: { id: number; email: string; ip: string | null; method: string; outcome: string; created_at: string }[];  // ip: the limiter's source - an IPv4 address, or an IPv6 address's /64
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
| `DELETE /api/admin/sessions/{id}` | ends it and its refresh tokens; the administrator's own, from this browser, is answered as a sign-out (the session cookie and the sign-in hint cleared) |
| `GET /api/admin/emails?kind=&status=&recipient=&from=&to=&page=` | `{ total, page, pageSize, items: EmailLogEntry[] }` |
| `GET /api/admin/emails/filters` | `{ kinds, statuses }` |
| `GET /api/admin/audit?actor=&action=&target_type=&...&page=` | `{ total, page, pageSize, items: AuditEntry[] }` - the anotoki library's route since 0.4 (below, "The admin tools") |
| `GET /api/admin/stats` | `{ users, disabled, admins, active_sessions, applications, signins_24h, failed_24h, emails_24h, awaiting_approval }` - `admins`: switched on and not on their way out (a pending deletion); `disabled` includes the deleted accounts; the last: accounts waiting for approval and switched on (0 while 012 is pending) |

`AdminSession` is a `SessionEntry` plus `user_id`, `username`, `email`.
`EmailLogEntry` is `{ id, recipient, kind, subject, status, error, user_id, username, created_at }`;
the kinds of migration 016 are `account_deletion_requested`,
`account_deletion_cancelled`, `account_deleted` (logged under the deleted
account's placeholder address and no `user_id`: the log keeps no address of
a deleted account, and every other row of it goes with the account) and
`new_sign_in`, each logged with its subject.
`AuditEntry` is `{ id, actor_id, actor_username, action, target_type, target_id, details, ip, created_at }`
and, since the log is the library's (migration 018), `actor_session`, `scope_type`, `scope_id` - always
null here ("The admin tools" below);
016 adds `account.deletion_requested` (`{ due, proof }`),
`account.deletion_cancelled` and `account.anonymized` (`{ requested_at }`,
by nobody).

### Announcements

News an administrator mails to the people of the sites (migration 013,
`backend/api/announcements.php`): from a template, a version of the words per
language, to everybody, the holders of some roles, or some people, from one of
the installation's addresses or one added here. Every route answers
`503 { code: "announcements_unavailable" }` while 013 is pending.

| Method + path | |
| --- | --- |
| `GET /api/admin/announcements?page=` | `{ total, page, pageSize, items: AnnouncementSummary[] }`, newest first, fifty a page |
| `GET /api/admin/announcements/compose` | `AnnouncementCompose` - what the composer starts from |
| `POST /api/admin/announcements/audience` | `{ audience: Audience }` → `AudienceCount` |
| `POST /api/admin/announcements/preview` | `Draft & { language }` → `{ subject, text, html, language, sender: { address, name } }` - the mail a reader of `language` gets: its version, or the English one; the stop link without a token |
| `POST /api/admin/announcements/test` | `Draft & { language }` → `{ sent: boolean, email }` - to the administrator's own address and nobody else's, the subject marked `[Test] `; `429 too_many_tests` after 20 in an hour |
| `POST /api/admin/announcements` | `Draft & { audience: Audience }` → `201 AnnouncementDetail` - made with its list of people; nothing is sent yet. `422 no_recipients` when nobody it names can get it |
| `GET /api/admin/announcements/{id}` | `AnnouncementDetail` |
| `GET /api/admin/announcements/{id}/recipients?status=&search=&page=` | `{ total, page, pageSize, items: { user_id, username, email, language, status, sent_at }[] }` - fifty a page; `email` is the address it went to, `language` the version |
| `POST /api/admin/announcements/{id}/send` | → `AnnouncementDetail & { wait: number \| null }` - the next batch (at most 20 mails, or 10 seconds), each mail in a transaction of its own; the page asks again while `status` is `sending`. `wait`: the seconds until the host's hourly allowance has room again (config/mail.local.php's `hourly_limit`; announcements keep to nine tenths of it). `409 stopped` once stopped |
| `POST /api/admin/announcements/{id}/stop` | → `AnnouncementDetail` - what is not sent yet never goes; `409 not_sending` |
| `GET /api/admin/mail-senders` | `{ senders: MailSender[], domains: string[] }` - the installation's own first (config/mail.local.php's `senders`), then the added ones |
| `POST /api/admin/mail-senders` | `{ address, name }` → `201 { sender, senders, domains }` - `422 invalid_email` / `foreign_domain` (only at one of `domains`) / `invalid_name` (letters, digits, spaces and `. ' & ! ? + _ -`, up to 60); `409 sender_exists` |
| `DELETE /api/admin/mail-senders/{id}` | an added one only → `{ senders, domains }`; what was sent from it keeps its address |

```ts
type Template = 'newVersion' | 'newRelease' | 'newSurvey' | 'blank';
interface Version { subject: string; heading: string; body: string; button: string }  // body: paragraphs, an empty line between two

interface Draft {
  template: Template;
  client_id: string;        // the site it is about: its name for {project}, and at the top of the mail; '' only for blank
  link: string;             // where the button leads; '' for none (then no button)
  sender: string;           // a MailSender id
  content: Record<string, Version>;   // by language code
}

interface Audience { everybody: boolean; roles: RoleGrant[]; user_ids: number[] }   // at most 100 roles, 500 people

interface AudienceCount {
  recipients: number;
  left_out: { disabled: number; unconfirmed: number; waiting: number; news_off: number; deleting: number };  // each person once, the first reason - in the order disabled, deleting (its deletion asked for, migration 016; 0 before it), unconfirmed, waiting, news_off
  languages: Record<string, number>;  // how many read which language (one switched off counts as English)
}

interface MailSender { id: string; address: string; name: string; added: boolean; created_at: string | null; created_by: string | null }
// id: the key in config/mail.local.php (noreply, support) for the installation's own; the number of an added one

interface AnnouncementCompose {
  templates: Record<'newVersion' | 'newRelease' | 'newSurvey', Record<string, Version>>;  // the mail strings, {project} still in them
  languages: { code: string; name: string; native_name: string }[];  // on offer, English first
  senders: MailSender[];
  applications: { client_id: string; name: string; home_url: string | null; system: boolean; enabled: boolean; roles: string[] }[];
  email: string;            // the administrator's own: where a test goes
  limits: { subject: 150; heading: 150; body: 5000; button: 60; link: 500; people: 500 };
  pacing: { driver: string; hourly_limit: number | null };  // `mail` really sends; the host's limit, if config/mail.local.php names one
}

interface AnnouncementSummary {
  id: number;
  template: Template;
  application: { client_id: string | null; name: string } | null;  // as it was sent
  subject: string;          // the English one
  languages: string[];      // the versions, English first
  sender: { address: string; name: string };  // as it was sent
  link: string | null;
  status: 'sending' | 'sent' | 'stopped';
  counts: { total: number; pending: number; sent: number; failed: number; skipped: number };
  created_by: string | null;
  created_at: string;
  finished_at: string | null;
}

interface AnnouncementDetail extends AnnouncementSummary {
  content: Record<string, Version>;
  audience: { everybody: boolean; roles: RoleGrant[]; users: { id: number; username: string; email: string }[] };
}
```

A draft is checked the same way by preview, test and create (preview and test
only the version their `language` gets, and English) - 422 with the server's
sentence: `invalid_template`, `application_required` / `unknown_application`,
`unknown_sender`, `invalid_link` (https - http only on this computer - with no
name before the host, at the IAM's host, a host an application is registered
with, or the domain the IAM sends mail from or one under it; printable ASCII
only, so no space of any kind and no backslash), `invalid_content` (lengths;
subject, heading and button one line), `english_required`,
`incomplete_version` (a language has all of its parts or none; a link needs a
button label), `unknown_language` (one not on offer), `placeholder_left` (a
`{project}` never filled), `foreign_address` (a link written in the words off
those hosts, or a mail address off the mail domain - read as a mail program
reads it: every kind of space and invisible character ends an address, and
each `://` and `www.` is judged on its own wherever it stands; the site's name
too, which stands at the top of every mail).

Sending is at least once: a mail is marked in the transaction that sends it,
so a process that dies between the two sends that one again on the next
batch. A failed email log write does not (a savepoint keeps it apart).

Who gets it: only accounts switched on, with the address confirmed, approved,
not being deleted (migration 016: an account whose owner asked for its
deletion gets no news in its last thirty days), and the news on - asked when
the list is made and again as each mail goes (one that no longer can is
`skipped`); the address it goes to is the account's at
that moment. Each mail is `multipart/alternative` (plain text and HTML, both
base64) with a `List-Unsubscribe` header naming the person's `/unsubscribe`
page, from the chosen sender (Reply-To the same), logged in the email log as
`announcement` (tests as `announcement_test`). The audit log has
`announcement.created`, `announcement.tested`, `announcement.stopped`,
`mail_sender.added`, `mail_sender.removed`, and `account.news_mail` (`by`:
`account` or `link`).

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

The IAM's own words: the languages it offers and the strings of its pages and
its mails. Keys are the code's - migrations write them, these routes only
change strings. English is what every other language falls back to, key by
key. The eight routes are the anotoki family's (anotoki-lib's
`TranslationsRoutes`, on the IAM's configuration in `api/translations.php`),
the same on every anotoki site, under this group's checks: the session and the
IAM's `ADMIN` for all eight - the IAM has no EDITOR of its own panel - and the
CSRF header on every write.

They answer in this order: `401` / `403` (the session, `ADMIN`; `403 csrf` for
a write without the header); `404 not_found` for a `{code}` that is not `xx` /
`xx-yy`, before anything else; then the body of a write, which is read from
the request as it came (not as a form or another parser saw it): at most 1 MB
by what `Content-Length` says (`413 too_large`), JSON only
(`415 unsupported_media_type`), at most 1 MB by what arrives (`413 too_large`),
not empty (`422 empty_body`), JSON (`400 invalid_json`) - and an object is kept
apart from a list: `{}` is an object with nothing in it, `[]` is a list, and a
list where an object belongs is refused like any other wrong shape; then
`503 translations_unavailable`, with
`Retry-After: 60`, until migration 006 is applied (from this same panel,
Migrations); then the work. JSON answers carry `Cache-Control: no-store`.

| Method + path | |
| --- | --- |
| `GET /api/admin/languages` | `{ languages: AdminLanguage[] }` - every language, the switched-off ones too, in order |
| `POST /api/admin/languages` | `{ code, name, native_name, enabled? }` → `201 { language: AdminLanguage }`, placed after the others. `enabled` left out means offered at once; the panel always sends it (a new language starts hidden there). |
| `PUT /api/admin/languages/{code}` | `{ name?, native_name?, enabled?, sort_order? }` - what is left out stays → `{ language: AdminLanguage }` |
| `DELETE /api/admin/languages/{code}` | the language and its strings go; its accounts go back to English (the foreign key's `ON DELETE SET DEFAULT`) → `{ strings, accounts }`: how many strings went with it, how many accounts went back to English |
| `GET /api/admin/translations` | `TranslationGrid` - every key, the `mail.` ones and the library's `anotoki.*` among them, with every language's string |
| `PUT /api/admin/translations` | `{ values: { key: { language: text } } }`, only what changed → `TranslationGrid` |
| `GET /api/admin/translations/{code}/export` | the language's own strings as a flat `{ key: text }`, not merged with English, `mail.` and `anotoki.` keys included; `{}` when it has none. A download in the file's format: `Content-Disposition: attachment; filename="translations-{code}.json"`, JSON pretty-printed with a line break at the end (what `php translations.php --export` writes) |
| `PUT /api/admin/translations/{code}/import` | a flat `{ key: text }` → `TranslationGrid`. The same checks as a save; an empty string is a key not translated yet and is passed over, so an import deletes nothing |

```ts
interface AdminLanguage {
  code: string;          // xx or xx-yy, small letters; fixed once made
  name: string;          // in English: "Slovak"
  native_name: string;   // as its speakers write it: "Slovenčina"
  enabled: boolean;      // offered: on the switcher, in discovery, a language an account can have
  sort_order: number;    // 0-1000, lowest first
  seeded: boolean;       // the migrations write strings for it (en, sk): cannot be deleted
  strings: number;       // how many strings of its own it has
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

Refusals of a save and an import - the body's (`413 too_large` over 1 MB,
`415 unsupported_media_type`, `422 empty_body`, `400 invalid_json`, above), and
otherwise `422`, the first thing wrong, with `key` and `language` beside `code`
and `error` (and `placeholder` where it is about one, `keys` for unknown keys):

| `code` | |
| --- | --- |
| `invalid_value` | not `{ key: { language: text } }` (an import: not a flat object); a value that is not text; a page's string over 2,000 characters; a mail's string with a line break or a tab; a mail's string with a character that turns the direction of the text - U+202A-202E, U+2066-2069, U+200E, U+200F, U+061C - or a C1 control (U+0080-009F): the panel checks a string in the order it is stored, a mail program shows it in the order those say (`moc.live//:sptth` after a right-to-left override reads as an address); a mail subject over 150 characters or another mail string over 400; a mail string of more bytes than one line of mail may have - 900, less 400 for every `{username}` in a greeting (the longest name there can be, kept for each: 500 with one, 100 with two, none with three), and less 10 for every `{date}` |
| `unknown_key` | a key the IAM does not have, empty or not (with `keys`: every such key sent) - a key that is a number among them, so a JSON list (`["Save", "Close"]`) or `{"0": ...}` is refused so (anotoki-lib 0.4.0; an empty list or a body that is no object stays `invalid_value`) |
| `unknown_language` | a language the IAM does not have |
| `fallback_required` | a blank English string: every other language falls back to it |
| `invalid_placeholder` | a `{` or `}` that is not part of a `{placeholder}`, in any string: `{ count }`, `{počet}`, `{count`, `{{count}}` |
| `placeholder_changed` | an English page string whose placeholders are not exactly the stored English string's (a library key's: the library's English's - `anotoki.language.button` keeps `{name}` and `{code}`, whatever the stored English says) |
| `unknown_placeholder` | a page string in another language with a placeholder its English string does not have; a mail string, in any language, with one the code does not fill for that key |
| `placeholder_missing` | `login.continueTo` or `register.forApp` without `{app}`; a mail string, in any language, without a placeholder the code fills for that key |
| `address_in_mail` | a mail string with something that reads as an address - `://`, `www.` or `@`. A mail's only link is the one the server puts in. |

A mail's placeholders are the code's (`MAIL_KEYS` in `api/mail.php`), not the
stored English: greetings take `{username}`, validity lines `{hours}` or
`{minutes}`, the sign-in code's subject `{code}`, the deletion mail's intro
`{date}` (the day it is due, as the account writes dates - `2026-11-06` when
it chose none). There is no placeholder for a mail's link or code - that line
is never part of a string - nor for the new sign-in mail's time and browser,
which the server writes on lines of their own.

The audit log records `language.created` (`name`, `native_name`, `enabled`),
`language.updated` (what changed), `language.deleted` (`name`, `accounts`,
`strings`), `translations.saved` and `translations.imported` (the languages,
how many strings changed, the first hundred keys, and `mail_changed`: how many
`mail.` strings changed) - and every changed `mail.` string's old and new
text whole in an entry of its own, `translations.mail_changed` (target
`translation` and the key; `{ language, old, new, part_of, via? }` - `part_of`
the save or import it belongs to, `via: "command line"` for the command
line's). One entry keeps at most 64 KB of details, so a save of many long mail
strings - padded on purpose, say - still keeps every one of them.

### Settings, keys, migrations

| Method + path | |
| --- | --- |
| `GET /api/admin/settings` | `{ settings: { name, group_name, type, options, value, updated_at, updated_by }[] }` |
| `PUT /api/admin/settings` | `{ settings: { name, value }[] }` → same |
| `GET /api/admin/keys` | `{ keys: { kid, alg, status: 'active'\|'retired', created_at, retired_at }[] }` |
| `POST /api/admin/keys/rotate` | a new active key; the old one is retired but stays in the JWKS a day |
| `DELETE /api/admin/keys/{kid}` | drops a retired key from the JWKS |
| `GET /api/admin/migrations` | `MigrationsStatus` (below) |
| `GET /api/admin/migrations/file?set={anotoki_admin\|iam\|anotoki_translations}&name={file}` | `{ set, name, sql }` - the file's SQL as it is on disk |
| `POST /api/admin/migrations/apply` | `ApplyResult` (below) - a backup of the database first |

Settings: `login_enabled`, `registration_enabled`, `registration_approval`
(since migration 012; off by default), `google_login_enabled` (booleans). A save
that switches `registration_approval` off approves every account still waiting
and switched on.

**Migrations** are the anotoki family's (anotoki/lib, the same three routes on
every anotoki site; the admin panel's Migrations page is the family's page,
`@anotoki/lib/migrations`), under this group's checks: the session, the IAM's
`ADMIN` - none of which needs a table a pending migration makes - and the CSRF
header on Apply. The engine applies the files the IAM has not recorded yet, in
order - the anotoki library's admin set (`anotoki_admin`, in
`vendor/anotoki/lib/php/migrations/admin`: the tables of the admin tools - the
audit log, and feedback, which the IAM does not use), those of
`migrations/iam`, then the library's translations set (`anotoki_translations`,
in `vendor/anotoki/lib/php/migrations/translations`: the tables of the words
proved, and the library's own words) - each in a transaction of its own with
its record in the `migrations` table, and stops at the first that fails
(rolled back whole: the files after it never run, the library's included). The
table keeps the IAM's files by their names and a library set's as `set/name`
(`anotoki_admin/001_audit_log.sql`, `anotoki_translations/001_languages_and_strings.sql`).
One apply at a time, whoever starts it - this page, `php migration.php`, the
installer: each takes the same lock.

Apply - here and `php migration.php` - first makes a backup of the database as
it is (a `before-migration` backup, "Backups" below; once for each list of
pending files, none on a database with nothing applied yet), and names it in
`note`. A backup that cannot be made applies nothing: `failed` is null, `error`
says why.

```ts
interface MigrationsStatus {
  sets: string[];   // ['anotoki_admin', 'iam', 'anotoki_translations']: the library's admin set, the IAM's files, the library's translations set
  applied: { set: string; name: string; applied_at: string | null }[];  // recorded and on disk, in the order they ran; ISO 8601 UTC
  pending: { set: string; name: string; ready: boolean; blocked: boolean }[];  // in the order they will run; the IAM has no drafts: ready true, blocked false
  missing: { set: string; name: string; applied_at: string | null }[];  // recorded as applied, but the file is not there
}

interface ApplyResult {
  applied: { set: string; name: string }[];
  failed: { set: string; name: string } | null;  // the file that stopped the run
  error: string | null;  // "statement N of M: <what PostgreSQL said>" and the statement's first six lines; or why the backup before it could not be made
  note: string | null;   // "A backup of the database as it was is <id>." - null when nothing was applied before
}
```

- `GET .../file` answers `404 { code: "not_found", message }` for anything
  that is not one of the set's files - no path is followed.
- Apply answers `200` whether or not a file failed (the page shows which, and
  what the database said), and `409 { code: "busy", message }` - nothing
  applied - while another apply runs.
- Every apply that was not busy is audited, `migrations.applied`, with
  `{ applied: string[], failed: string | null, error: string | null }` (the
  files' names - a library set's as `set/name`) - one with nothing to apply too.
  The audit entries that waited for migration 018 go in before it, with their
  own times ("The admin tools" below).

### The admin tools: audit log, error log, backups

The anotoki family's (anotoki-lib 0.4, and 0.5.0 since; `docs/decisions.md`,
2026-10-11): the same routes and answers on every anotoki site, the admin panel's Audit log,
Error log and Backups pages drawing them (`@anotoki/lib/admin/*`). Here they
sit inside the admin group - the session, the IAM's `ADMIN` (not while one's
own deletion is pending), the CSRF header on every write - and each group asks
the library's own ADMIN check besides, the same rule, so that one mounted
anywhere else would let nobody in either. Refusals are in the IAM's body,
`{ error, code }`.

#### Audit log

| Method + path | |
| --- | --- |
| `GET /api/admin/audit?actor=&action=&target_type=&target_id=&scope_type=&scope_id=&from=&to=&page=` | `{ total, page, pageSize: 50, items: AuditEntry[] }`, newest first |
| `GET /api/admin/audit/facets` | `{ actions: string[], target_types: string[], scope_types: string[], actors: { id, username }[] }` - the page's choices, at most 500 of each |

- `actor`: an id (digits alone), or a part of a username, regardless of case;
  `action`: its beginning (`account.` finds every `account.*`; `%` and `_` are
  characters like any other); `from`, `to`: a date (`to` is the whole of its
  day) or an ISO 8601 time. A filter that is none is no filter.
- `503 { code: "feature_unavailable" }` (`Retry-After: 60`) until the library's
  table and migration 018 are there: the entries recorded meanwhile wait in a
  file and go in after the Apply, with their own times.

```ts
interface AuditEntry {
  id: number;
  created_at: string;            // ISO 8601 UTC, to the millisecond
  actor_id: number | null;       // the account that acted; null: nobody (housekeeping, the command line, a link followed)
  actor_username: string | null; // its username as it is now - an anonymized account's placeholder
  action: string;                // area.verb: user.roles_set, account.deletion_requested, backup.created, ...
  target_type: string | null;
  target_id: string | null;
  details: unknown;              // what changed, as the action records it
  ip: string | null;             // the requester's address ("Where a request comes from": the visitor's through Cloudflare's ranges)
  actor_session: string | null;  // the library's three: always null here
  scope_type: string | null;
  scope_id: string | null;
}
```

Every admin change and security-relevant self-service change is an entry (the
actions are under each area above); the tools add `errors.cleared`
(`{ files, before? }`), `backup.created` (`{ kind, size, tables, rows,
encrypted, description? }` - by nobody for the daily backup and the one before
an update), `backup.downloaded` and `backup.deleted` (`{ kind, size }`).
`legacy.imported` and `user.password_reset_cli` are by nobody, from no
address. When an account is deleted its person is taken out of the log by the
IAM's rules ("Deleting the account"): no address on its own entries or on those
about it nobody else made, its names and addresses out of the details of what
was about it, a personal token's name - an administrator's address on an
administrator's entry stays.

#### Error log

| Method + path | |
| --- | --- |
| `GET /api/admin/errors?days=&level=&status=&search=` | `ErrorLogReport` - `days` 1 to 30 (7) |
| `GET /api/admin/errors/{fingerprint}?days=&level=&status=&search=` | every occurrence of one kind (`ErrorLogEntry[]`); `404 not_found` for none |
| `DELETE /api/admin/errors?before=YYYY-MM-DD` | `{ deleted }` - the files gone: every day's, or (with `before`, a UTC date) the days before it; `422 date_invalid` for anything else; audited `errors.cleared` |

What fails is written down in `storage/logs/errors-YYYY-MM-DD.jsonl` (UTC
days, 30 kept, 64 MB at most, a hundred lines of one kind a day), one JSON line
an event: an exception the IAM answered (with its status and code - and, for
one thrown for another, each cause's `Caused by Type (file:line): message` in
its trace), an answer worth seeing (every 5xx, 404, 405, 413, 415, 429), PHP's
warnings and fatal errors, and the pages' own errors (`source: "client"`,
`POST /api/client-errors` above). An answer that says a state, not a failure
(`update_pending`, `feature_unavailable`, `not_set_up`), is not written. Never a
query string, a header, a cookie, a body, a token or the session; every
message - each cause's too - only redacted: the library's rules (`password=`
and its kin, Bearer credentials, JWTs, runs of a token's alphabet, e-mail and
IP addresses, PostgreSQL's DETAIL), with the IAM's names of secrets among them
(a current or new password, a remembered device's token, a code, a recovery
code, a two-factor secret, Google's credential - `Redactor::addSecretNames()`),
and before them the IAM's own prefixes and cookies (`iamRedactSecrets()`: its
session and device cookies, `iam_rt_` and `iam_pat_` tokens); a trace without a
call's arguments; the address as a keyed hash of its bucket (12 hex - an IPv6
address its /64), never itself. Requests to these routes are never written
into it.

```ts
interface ErrorLogEntry {
  v: number; at: string;                           // ISO 8601 UTC, to the millisecond
  source: 'server' | 'php' | 'client'; level: 'error' | 'warning' | 'notice';
  status: number; type: string; code: string | null; message: string;
  file: string | null; line: number | null;        // relative to backend/
  method: string | null; route: string | null; path: string | null;
  user_id: number | null; client: string | null;   // 12 hex of a keyed hash of the address
  trace: string[] | null; fingerprint: string;     // 12 hex: one kind of failure
}
interface ErrorLogReport {
  total: number; errors: number; warnings: number; notices: number; distinct: number;
  truncated: boolean;                              // more lines than the 2,000 newest read
  days: string[]; daily: { key: string; errors: number; warnings: number; notices: number }[];
  statuses: { key: string; errors: number; warnings: number; notices: number }[];
  groups: { fingerprint: string; level: string; status: number; type: string; code: string | null; message: string;
            file: string | null; line: number | null; route: string | null; source: string;
            count: number; first_at: string; last_at: string; paths: string[]; latest: ErrorLogEntry }[];
  dropped: Record<string, number>;                 // lines only counted, by UTC date
}
```

#### Backups

| Method + path | |
| --- | --- |
| `GET /api/admin/backups` | `{ backups: BackupInfo[], status: BackupsStatus }`, newest first |
| `GET /api/admin/backups/proof` | `{ kind: 'password' \| 'recent_sign_in' }` - what the page asks for |
| `POST /api/admin/backups` | `{ description?, password? }` → `201 { backup: BackupInfo }`; audited `backup.created` |
| `GET /api/admin/backups/{id}` | `{ backup: BackupInfo }`, with its tables |
| `DELETE /api/admin/backups/{id}` | `{ password? }` → `{ deleted: true }`; audited `backup.deleted` |
| `POST /api/admin/backups/{id}/ticket` | `{ password? }` → `{ url, expires_in: 60 }`; audited `backup.downloaded` |
| `GET /api/admin/backups/{id}/file?ticket=` | the file, as an attachment: `application/octet-stream`, `Content-Disposition: attachment; filename="iam-{id}.anotoki-backup"`, `no-store`, `nosniff`, `no-referrer`; `403 ticket_invalid` |
| `GET /api/admin/backups/{id}/preview` | what a restore would take away, table by table - `restorable: false` here, with the reason |
| `POST /api/admin/backups/{id}/restore` | `403 restore_disabled`, always: restoring is switched off on the IAM |

- **The proof**, for a backup made, downloaded or deleted - the account page's
  ("The proof a session alone cannot give"): the administrator's password where
  it is a way in (`password`), else a sign-in of the last 15 minutes. `422
  wrong_password` (counted by the sign-in limiter, as `admin_backup` in the
  person's sign-in attempts), `403 reauthentication_required`, `429
  too_many_attempts`. A body is JSON - nothing, or an object.
- **Encrypted**, every one: AES-256-GCM in chunks under a key derived from the
  master key (HKDF, `anotoki-lib/iam-backup/v1`, a fresh salt each) - its rows
  never in the clear on the disk, not even while it is made. A backup holds
  every table: every account's password hash, the two-factor secrets and
  signing keys (under the master key besides), every hash of a session and a
  token. Without the master key a downloaded one opens nothing
  (`php backup.php`, `docs/operations.md`).
- **Never restored here** (`status.restore_enabled: false`): an IAM put back
  as it was would bring back revoked tokens and sessions, old passwords,
  removed roles and deleted people (`docs/decisions.md`, 2026-10-11).
- **The ticket** is good once, for a minute, for that backup and the
  administrator it was issued to - and the route sits in the admin group, so
  the session and the IAM's `ADMIN` are asked as well. The page navigates to
  `url` (the browser writes the file to disk); used, expired, of another backup
  or another administrator, it is `403 ticket_invalid`, spent by the try.
- **Kinds**: `daily` (the first housekeeping of a UTC day, made after the
  answer, while nothing waits to be applied; 14 kept), `before-migration`
  (Apply's, above; 5 kept), `manual` (until deleted; `stale` after 90 days).
  Only files of the library's own names are ever deleted. `409 busy` while
  another backup or an Apply runs, `507 no_space` when the disk has less than
  twice the last backup free.

```ts
interface BackupInfo {
  id: string;                    // YYYYMMDD-HHMMSSZ-<kind>-<32 hex>
  site: 'iam'; kind: 'daily' | 'manual' | 'before-migration' | 'before-restore';
  created_at: string; library_version: string | null;
  encrypted: true; status: string; file: string;
  size: number;                  // bytes on disk
  created_by: number | null;     // the administrator; null for the daily and the before-migration ones
  created_by_name: string | null;  // their username as it is now (an anonymized account's placeholder); null for nobody
  sealed: boolean;               // made here: true for every one of the IAM's, whose cipher is its seal (anotoki-lib 0.5.0)
  description: string | null;
  table_count: number; rows: number; sequences: number; duration_ms: number;
  stale: boolean;                // a manual one kept for more than 90 days
  restorable: boolean;           // false here
  tables?: { name: string; rows: number; bytes: number }[];  // GET {id} alone
}
interface BackupsStatus {
  directory_writable: boolean; free_bytes: number | null; last_daily: string | null;
  copy_available: boolean; encrypted: true; cipher_available: boolean;
  restore_enabled: false; restorable: false; problems: string[];
  count: number; total_bytes: number; database_bytes: number | null;
  schema: { migrations_sha256: string; columns_sha256: string } | null;
}
```

### Public

`GET /api/applications` - every application that is switched on, but the IAM
itself, in the order they were added (`sort_order`, then name; migration 011
took the landing page settings away, so `show_on_landing` is not read):
`[{ client_id, name, description, home_url, logo_url }]`. CORS open,
cacheable. The anotoki.eu landing page keeps its own list.

`GET /api/public/applications/{client_id}` - whether a site requires signing
in, for the sites' backends: under OpenID Connect above.

`GET /api/public/anonymized-accounts` - the ids of the accounts deleted at
their owners' request, for the sites' backends: under OpenID Connect above.
