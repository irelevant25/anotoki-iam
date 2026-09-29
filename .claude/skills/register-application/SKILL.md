---
name: register-application
description: >
  Add a site (an OIDC client) to the anotoki IAM and wire the site up to sign in
  through it. Use for "add a new site to the IAM", "register app X", "the site
  moved domain", "add a redirect URI", "give app X roles", "make Y members-only".
---

# Register an application

A site signs in through the IAM once it exists here as an **application**: a
client id, exact redirect URIs, the origins allowed to call the token endpoint,
its roles, and who may use it. The contract for the site's side is
`docs/integrating-an-app.md` - read it before starting.

## 1. The application

Decide, with the owner where it is not obvious:

| Field | Guidance |
| --- | --- |
| `client_id` | short, lowercase, fixed forever (`piano-academy`) - sites and tokens carry it |
| `type` | `public` for a browser app (PKCE, no secret). `confidential` only for a server that can keep a secret. |
| redirect URIs | exactly where the callback lands: `{frontend}/auth/callback`, the SPA route every anotoki site uses |
| post-logout URIs | the site's `{frontend}/` |
| web origins | the site's origin, `{frontend}` - scheme + host (+ port) |
| roles | capitals: `ADMIN`, plus what the site needs (`EDITOR`, `MEMBER`) |
| access | `everyone` for a public site; `members` when only role holders may use it |
| access token TTL | 600 s unless there is a reason |

`{frontend}` is the site's development address - its `ng serve` port in the
port table (`README.md`), `http://localhost:4200`, `4400`, … - in anything
committed. A new first-party site takes the next free pair of ports, and the
table in `README.md`, `docs/architecture.md` and `docs/integrating-an-app.md`
gets its row.

**For a first-party site that belongs in every installation**, write a
migration (see the iam-migration skill) inserting the application, its
development URIs, origin and home, and its roles - model it on
`backend/migrations/iam/002_first_party_applications.sql` and give it the same
`DO $$` check. Then add it to `SETUP_SITES` in `backend/setup.php` (a real
host's install asks for its production address; the example there is only a
placeholder), to `DEV_CLIENTS` in `backend/clients.php`, and to the port-table
test in `backend/tests/setup.test.php`.

**For anything else** - and for production addresses on an installed IAM - the
admin console → Applications → Add or the application's page does it.

## 2. Development addresses

A database built from the migrations already has them. On one that has drifted,
`php clients.php --dev` (in `backend/`) makes every seeded site's loopback
addresses exactly the port table's again and leaves anything else alone; it
refuses on an IAM whose own address is not on this computer.

## 3. The site's side

The site vendors `sdk/js/anotoki-auth.mjs` (or `.js` for classic scripts) and
`sdk/php/anotoki_iam.php` (or its own verifier following the same rules), and:

- configures issuer (`http://localhost:4300` in development), client id,
  redirect URI, post-logout URI - from its own configuration, never hard-coded;
- runs `init()` before drawing; serves the callback route;
- sends `Authorization: Bearer` from `getAccessToken()`;
- verifies on the backend: RS256, `typ at+jwt`, `iss`, `aud`, `exp`; `sub` is the
  user id, `roles` the site's roles;
- keeps its own per-user data keyed by `sub` and never a password.

## 4. Check

- `php clients.php` lists it with the right addresses.
- A sign-in from the site's dev server reaches the IAM's login page with the
  site's name on it, and comes back with a code; the site's API accepts the token.
- A URI that is off by one character is refused with `/error?code=invalid_redirect_uri`.
- For `members`: an account with no role gets `access_denied`; granting a role
  lets it in; taking the role away stops the next refresh.

Tell the owner which roles to grant to whom - the IAM starts with nobody holding
a new application's roles.
