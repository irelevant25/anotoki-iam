# anotoki IAM

One account for every anotoki site. The IAM is where people sign up, sign in
and manage their account; the genshin site, Japanese Academy, Piano Academy,
the build analyzer and the survey site send them here and get them back
signed in. It is a small OpenID Connect provider - the protocol Keycloak
speaks - built for the owner's FTP-only PHP + PostgreSQL hosting, where it
runs as its own subdomain.

- **Sign-in**: password, a six-digit code by email, or Google; two-factor with
  an authenticator app and recovery codes; remembered devices; "keep me signed
  in"; forgotten passwords; confirmed addresses.
- **Single sign-on**: signed in once, every other site bounces through without
  a form. Signing out ends it everywhere.
- **Account page**: username and language, password, Google, two-factor,
  sessions, the sites you can use, tokens for scripts.
- **Admin console**: accounts, per-site roles, sessions, the mail log, the sites
  themselves (redirect URIs, origins, access policy), switches, signing keys,
  migrations, an audit log of every change - and the words: every sentence of
  the sign-in and account pages and of the three mails is a string in the
  database, reworded there, and a new language is added there, with no build.
  English and Slovak come with it.
- **Installer**: `backend/setup.php` writes the configuration, builds the
  database and makes the first administrator - on a development machine as on
  a server. Until it has run, the IAM says it is not set up and does nothing
  else.

| Part | Folder | Stack |
| --- | --- | --- |
| Backend | `backend/` | PHP 8.2+, Slim 4, PostgreSQL |
| Frontend | `frontend/` | Angular 21, standalone, zoneless, signals |
| SDKs | `sdk/` | the browser client and the PHP token verifier every site copies |
| Docs | `docs/` | architecture, API, integration, operations, decisions |

On a development machine it runs at `http://localhost:4300` (the pages, with
the API behind them on `:8100`), and every anotoki site's own dev server has a
fixed port it is registered with:

| Application | client_id | Frontend | Backend |
| --- | --- | --- | --- |
| IAM | `iam` | `http://localhost:4300` | `:8100` |
| Genshin Impact | `genshin` | `http://localhost:4200` | `:8000` |
| Japanese Academy | `japanese-academy` | `http://localhost:4400` | `:8200` |
| Piano Academy | `piano-academy` | `http://localhost:4500` | `:8300` |
| Build Analyzer | `build-analyzer` | `http://localhost:4600` | `:8400` |
| Surveys | `survey` | `http://localhost:4700` | `:8500` |

- Getting it running: [DEV.md](DEV.md).
- How it works: [docs/architecture.md](docs/architecture.md), then
  [docs/api.md](docs/api.md) for the endpoints.
- Making a site sign in through it: [docs/integrating-an-app.md](docs/integrating-an-app.md)
  and [sdk/README.md](sdk/README.md).
- Putting it on the hosting: [docs/operations.md](docs/operations.md).
- Why it is built the way it is: [docs/decisions.md](docs/decisions.md).
