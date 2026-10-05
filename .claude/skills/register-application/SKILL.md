---
name: register-application
description: >
  Add a site (an OIDC client) to the anotoki IAM and wire the site up to sign in
  through it. Use for "add a new site to the IAM", "register app X", "the site
  moved domain", "add a redirect URI", "give somebody a role in app X", "make Y members-only", "let visitors into Y", "block somebody from Y".
---

# Register an application

A site signs in through the IAM once it exists here as an **application**: a
client id, exact redirect URIs, the origins allowed to call the token endpoint,
which of its roles may sign in, and whether it requires signing in. The contract for the site's side is
`docs/integrating-an-app.md` - read it before starting.

## 1. The application

Decide, with the owner where it is not obvious:

| Field | Guidance |
| --- | --- |
| `client_id` | short, lowercase, fixed forever (`piano-academy`) - sites and tokens carry it |
| client type | none to choose: every application is a public client (migration 011) - PKCE, no secret; a request carrying a secret is refused |
| redirect URIs | exactly where the callback lands: `{frontend}/auth/callback`, the SPA route every anotoki site uses |
| post-logout URIs | the site's `{frontend}/` |
| web origins | the site's origin, `{frontend}` - scheme + host (+ port) |
| roles | fixed, nothing to choose: every site has `ADMIN`, `EDITOR` and `MEMBER`, made with the application (migrations 010 and 011) - EDITOR opens its admin area, ADMIN all of it (the site's own split; `docs/integrating-an-app.md`); every account holds MEMBER. The admin panel neither adds, removes, renames nor rewords a role |
| roles that may sign in | each role's tick (`may_sign_in`), all yes: a sign-in needs a held role that may. "Make Y members-only" = untick Y's MEMBER (Applications -> Y -> Settings -> Roles), then give its people ADMIN or EDITOR by hand. "Block somebody from Y" = take Y's MEMBER from them (Users -> the person -> Roles); the users list then says "Cannot sign in: Y", and Y's Members tab lists them |
| sign-in required | `login_required`, a switch on the application's page: on (the default) - the site sends everybody to sign in; off - it lets visitors in (genshin and the build analyzer). The site reads it within about a minute |
| access token TTL | 600 s unless there is a reason |

`{frontend}` is the site's development address - its `ng serve` port in the
port table (`README.md`), `http://localhost:4200`, `4400`, … - in anything
committed. A new first-party site takes the next free pair of ports, and the
table in `README.md`, `docs/architecture.md` and `docs/integrating-an-app.md`
gets its row.

**For a first-party site that belongs in every installation**, write a
migration (see the iam-migration skill) inserting the application, its
development URIs, origin and home, and its roles' own words if it wants them
(the trigger gives ADMIN, EDITOR and MEMBER generic words as the application
is inserted; the migration rewords them with an `UPDATE` where they still
have those words - the iam-migration skill) - model it on
`backend/migrations/iam/005_survey_application.sql`, `DO $$` check included.
Unlike 002, which seeded the first sites before any IAM was installed, it also
reaches IAMs that are installed already (the Migrations page), one of them
live: so, like 005, it adds the development addresses only while the IAM's own `iam` entry has its home on this computer, and leaves
an entry that is there already as it is. Like 005 (and 007 for the sites
before it), it gives the site's ADMIN to every administrator the IAM has -
`createFirstAdmin()` and the legacy import give a later administrator every
application's ADMIN by themselves. Then add it to
`SETUP_SITES` in `backend/setup.php` (a real host's install asks for its
production address; the example there is only a placeholder), to `DEV_CLIENTS`
in `backend/clients.php`, and to the installer's tests: the port-table test in
`backend/tests/setup.test.php`, the real-host install in
`backend/tests/setup-remote.test.php`. On the live IAM the admin panel then
gives it its https addresses (the deploy-iam skill, "Later updates").

**For anything else** - and for production addresses on an installed IAM - the
admin panel → Applications → Add or the application's page does it.

## 2. Development addresses

A database built from the migrations already has them; one set up before a
site's migration gets the site, addresses and all, once the migration is
applied (saving `setup.php` again, or `php migration.php`). On one that has
drifted, `php clients.php --dev` (in `backend/`) makes every seeded site's
loopback addresses exactly the port table's again and leaves anything else
alone; it refuses on an IAM whose own address is not on this computer.

## 3. The site's side

The site vendors `sdk/js/anotoki-auth.mjs` (or `.js` for classic scripts) and
`sdk/php/anotoki_iam.php` (or its own verifier following the same rules), and:

- configures issuer (`http://localhost:4300` in development), client id,
  redirect URI, post-logout URI - from its own configuration, never hard-coded;
- runs `init()` before drawing; serves the callback route;
- sends `Authorization: Bearer` from `getAccessToken()`;
- verifies on the backend: RS256, `typ at+jwt`, `iss`, `aud`, `exp`; `sub` is the
  user id, `roles` the site's roles (`anotoki_iam_roles()`): `ADMIN` or `EDITOR`
  for every admin route, `ADMIN` alone for deleting and system pages, checked
  on the server; `["MEMBER"]` (or no roles, from an IAM before 010) is an
  ordinary signed-in person - no check needs MEMBER and none refuses it;
- keeps its own per-user data keyed by `sub` and never a password.

## 4. Check

- `php clients.php` lists it with the right addresses.
- A sign-in from the site's dev server reaches the IAM's login page with the
  site's name on it, and comes back with a code; the site's API accepts the token.
- A URI that is off by one character is refused with `/error?code=invalid_redirect_uri`.
- An account given no role signs in to a first-party site and gets a token
  with `roles: ["MEMBER"]`.
- The application's page shows ADMIN, EDITOR and MEMBER, ticked, and the
  sign-in switch as decided; the public `GET /api/public/applications/{client_id}`
  says the same `login_required`.
- With the site's MEMBER unticked: an account holding only MEMBER gets
  `access_denied`; granting a role that may sign in lets it in; taking that
  role away stops the next refresh.

An administrator of the IAM holds ADMIN in every application that has one -
the owner's rule: the installer's (or `create-admin.php`'s) administrator is
made so, an ADMIN brought over from the genshin site too, a first-party site's
migration gives its ADMIN to the administrators already there (005, 007), and
an application added in the admin panel gets nobody's until it is given.
Tell the owner which EDITOR to grant to whom - nobody holds it at first.
