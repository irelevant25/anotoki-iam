-----------------------------------------------------------
-- THE IAM
--
-- One database holding who everybody is, how they prove it, and which of the
-- anotoki sites they may use. Every site signs people in through here and
-- keeps nothing of this itself.
--
-- The account tables are the ones the genshin site grew over thirty-odd
-- migrations - addresses confirmed by mail, passwords that may be absent or
-- switched off, Google identities, two-factor, remembered devices, one-time
-- tokens, a login limiter and a mail log - brought over with their rules
-- intact. What is new is what a shared sign-in needs on top: applications and
-- their roles, the authorization requests and codes of OpenID Connect, refresh
-- tokens, and the keys tokens are signed with.
--
-- Times are TIMESTAMPTZ and every connection runs in UTC, so a stored instant
-- means one thing whatever the server's own time zone is - the genshin tables
-- used TIMESTAMP, which reads differently on a server in another zone.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS migrations (
    id         SERIAL          PRIMARY KEY,
    filename   VARCHAR(255)    NOT NULL UNIQUE,
    applied_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP
);

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-----------------------------------------------------------
-- ACCOUNTS
--
-- The id is the identity every site keys its own data on - it travels as the
-- `sub` claim - so it is never reused and never renumbered. Accounts brought
-- over from the genshin site keep the id they had there, which is what keeps
-- every created_by and user_id in that site's own tables pointing at the right
-- person.
--
-- Usernames and addresses are unique regardless of case. "Admin" and "admin"
-- are one name to anybody reading it, and two accounts differing only in the
-- case of an address are one mailbox.
--
-- `password` is nullable: an account made through Google has none, and absent
-- says that better than a hash of nothing that something could one day be
-- checked against. `password_login_enabled` is the separate decision to have a
-- password and not accept it.
--
-- `totp_secret` is stored encrypted with the installation's master key (see
-- api/crypto.php). A copy of this database without config/secret.local.php can
-- therefore not generate anybody's codes - which the genshin table, holding the
-- secret as it is, could not say.
--
-- `disabled` is what the genshin table called `deleted`: nothing is destroyed,
-- the account simply stops working everywhere.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id                      SERIAL          PRIMARY KEY,
    username                VARCHAR(100)    NOT NULL,
    email                   VARCHAR(255)    NOT NULL,
    email_confirmed         BOOLEAN         NOT NULL DEFAULT FALSE,
    password                VARCHAR(255),
    password_login_enabled  BOOLEAN         NOT NULL DEFAULT TRUE,
    totp_secret             TEXT,
    totp_enabled            BOOLEAN         NOT NULL DEFAULT FALSE,
    force_password_change   BOOLEAN         NOT NULL DEFAULT FALSE,
    language                VARCHAR(10)     NOT NULL DEFAULT 'en' CHECK (language IN ('en', 'sk')),
    disabled                BOOLEAN         NOT NULL DEFAULT FALSE,
    last_login_at           TIMESTAMPTZ,
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS users_username_lower ON users (lower(username));
CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower ON users (lower(email));

CREATE OR REPLACE TRIGGER trg_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-----------------------------------------------------------
-- WHO AN ACCOUNT IS ELSEWHERE
--
-- A row is the claim "this Google user is this account". `subject` is the
-- provider's permanent id, never the address - people change addresses, and
-- Google's `sub` does not move. One provider identity is one account, and one
-- account holds at most one identity per provider.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_identities (
    id          SERIAL          PRIMARY KEY,
    user_id     INT             NOT NULL REFERENCES users(id),
    provider    VARCHAR(32)     NOT NULL,
    subject     VARCHAR(255)    NOT NULL,
    email       VARCHAR(255),
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS user_identities_provider_subject ON user_identities (provider, subject);
CREATE UNIQUE INDEX IF NOT EXISTS user_identities_user_provider ON user_identities (user_id, provider);

-----------------------------------------------------------
-- ONE-TIME SECRETS THAT ARRIVE BY MAIL
--
-- The confirmation link, the password reset and the emailed sign-in code. Only
-- the SHA-256 is kept - the secret itself exists only in the message. They
-- expire, they are marked consumed rather than deleted, and a sign-in code
-- counts wrong guesses so six digits cannot be worked through for free.
--
-- `context` is where a link remembers what it was part of: the application a
-- registration started from, so the confirmation can offer the way back to it.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_tokens (
    id          SERIAL          PRIMARY KEY,
    user_id     INT             NOT NULL REFERENCES users(id),
    purpose     VARCHAR(32)     NOT NULL CHECK (purpose IN ('email_confirm', 'password_reset', 'login_code')),
    token_hash  CHAR(64)        NOT NULL,
    context     JSONB,
    attempts    INT             NOT NULL DEFAULT 0,
    expires_at  TIMESTAMPTZ     NOT NULL,
    consumed_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS user_tokens_hash ON user_tokens (token_hash);
CREATE INDEX IF NOT EXISTS user_tokens_user_purpose ON user_tokens (user_id, purpose);

-----------------------------------------------------------
-- TWO-FACTOR: RECOVERY CODES, AND DEVICES THAT NEED NOT ASK AGAIN
--
-- Recovery codes are as strong as the password, so they are hashed like one
-- and spent on use. A remembered device is a browser that answered a code in
-- the last thirty days; it skips the six digits and nothing else. Both are
-- hashed - the browser or the paper holds the secret, the table is worth
-- nothing to anybody who reads it.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id          SERIAL          PRIMARY KEY,
    user_id     INT             NOT NULL REFERENCES users(id),
    code_hash   CHAR(64)        NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS user_recovery_codes_user_hash ON user_recovery_codes (user_id, code_hash);

CREATE TABLE IF NOT EXISTS user_trusted_devices (
    id           SERIAL         PRIMARY KEY,
    user_id      INT            NOT NULL REFERENCES users(id),
    token_hash   CHAR(64)       NOT NULL,
    user_agent   VARCHAR(255),
    ip           VARCHAR(45),
    created_at   TIMESTAMPTZ    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMPTZ,
    expires_at   TIMESTAMPTZ    NOT NULL,
    revoked_at   TIMESTAMPTZ
);

CREATE UNIQUE INDEX IF NOT EXISTS user_trusted_devices_token ON user_trusted_devices (token_hash);
CREATE INDEX IF NOT EXISTS user_trusted_devices_user ON user_trusted_devices (user_id, created_at DESC);

-----------------------------------------------------------
-- SESSIONS
--
-- A session is a signed-in browser at the IAM: the `anotoki_iam` cookie holds
-- a random secret and this row holds its SHA-256. It is what single sign-on
-- is - a site sending somebody here finds the session and gets a code back
-- without a form - and every refresh token an application holds names the
-- session it was issued under, so ending the session ends them all.
--
-- `sid` is a second random id, the one tokens carry as their `sid` claim. It
-- is not the cookie's secret and knowing it gets nobody in; it is there so a
-- site's own records ("this change was made from that sign-in") can name a
-- session without holding anything that opens it.
--
-- `expires_at` slides forward while the session is used, up to
-- `absolute_expires_at`. `auth_time` is when credentials were last presented,
-- which is what an application's `max_age` and `prompt=login` are about.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS sessions (
    id                   SERIAL          PRIMARY KEY,
    user_id              INT             NOT NULL REFERENCES users(id),
    token_hash           CHAR(64)        NOT NULL,
    sid                  CHAR(32)        NOT NULL,
    method               VARCHAR(16)     NOT NULL,
    mfa                  BOOLEAN         NOT NULL DEFAULT FALSE,
    remember             BOOLEAN         NOT NULL DEFAULT FALSE,
    ip                   VARCHAR(45),
    user_agent           VARCHAR(255),
    created_at           TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    auth_time            TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at         TIMESTAMPTZ,
    expires_at           TIMESTAMPTZ     NOT NULL,
    absolute_expires_at  TIMESTAMPTZ     NOT NULL,
    revoked_at           TIMESTAMPTZ,
    revoked_reason       VARCHAR(32)
);

CREATE UNIQUE INDEX IF NOT EXISTS sessions_token ON sessions (token_hash);
CREATE UNIQUE INDEX IF NOT EXISTS sessions_sid ON sessions (sid);
CREATE INDEX IF NOT EXISTS sessions_user ON sessions (user_id, created_at DESC);

-----------------------------------------------------------
-- SIGN-IN ATTEMPTS
--
-- Every try, good or bad, by address and source. The limiter counts failures
-- per address-and-source (so nobody can lock somebody else out by failing at
-- their account) and per source (so one machine cannot work through a list).
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS login_attempts (
    id          SERIAL          PRIMARY KEY,
    email       VARCHAR(255)    NOT NULL,
    user_id     INT             REFERENCES users(id),
    ip          VARCHAR(45),
    method      VARCHAR(16)     NOT NULL,
    outcome     VARCHAR(24)     NOT NULL,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS login_attempts_email_ip ON login_attempts (email, ip, created_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, created_at DESC);
CREATE INDEX IF NOT EXISTS login_attempts_user ON login_attempts (user_id, created_at DESC);

-----------------------------------------------------------
-- WHAT WAS MAILED
--
-- Metadata only. The bodies carry live secrets, and the sign-in code rides in
-- its subject, so no body is kept and a subject only for the kinds whose
-- subject holds nothing secret.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS email_log (
    id          SERIAL          PRIMARY KEY,
    recipient   VARCHAR(320)    NOT NULL,
    kind        VARCHAR(64)     NOT NULL,
    subject     VARCHAR(255),
    status      VARCHAR(16)     NOT NULL,
    error       TEXT,
    user_id     INT             REFERENCES users(id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS email_log_created_at ON email_log (created_at DESC);
CREATE INDEX IF NOT EXISTS email_log_kind ON email_log (kind);
CREATE INDEX IF NOT EXISTS email_log_status ON email_log (status);

-----------------------------------------------------------
-- APPLICATIONS
--
-- Each anotoki site is an OpenID Connect client. `client_id` is the short,
-- fixed name it signs in as; the rest is what it is allowed to ask for.
--
-- Redirect URIs are matched byte for byte, never by prefix or pattern: a
-- redirect URI is where a code is sent, and "anything under this path" is how
-- a code ends up on a page an attacker controls. They and the web origins are
-- rows rather than an array column so each is a value that can be looked up,
-- listed and removed on its own.
--
-- `access` decides who may use it: 'everyone' with an account, or 'members' -
-- those holding at least one of its roles. A public reference site is the
-- first; a personal study app the second.
--
-- `system` marks the IAM itself, which appears here only to own the role that
-- makes somebody an administrator of this installation.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS applications (
    id                  SERIAL          PRIMARY KEY,
    client_id           VARCHAR(64)     NOT NULL UNIQUE CHECK (client_id ~ '^[a-z0-9][a-z0-9-]{1,63}$'),
    name                VARCHAR(100)    NOT NULL,
    description         TEXT,
    home_url            VARCHAR(500),
    logo_url            VARCHAR(500),
    type                VARCHAR(16)     NOT NULL DEFAULT 'public' CHECK (type IN ('public', 'confidential')),
    secret_hash         VARCHAR(255),
    enabled             BOOLEAN         NOT NULL DEFAULT TRUE,
    access              VARCHAR(16)     NOT NULL DEFAULT 'everyone' CHECK (access IN ('everyone', 'members')),
    access_token_ttl    INT             NOT NULL DEFAULT 600 CHECK (access_token_ttl BETWEEN 60 AND 3600),
    show_on_landing     BOOLEAN         NOT NULL DEFAULT FALSE,
    sort_order          INT             NOT NULL DEFAULT 0,
    system              BOOLEAN         NOT NULL DEFAULT FALSE,
    created_at          TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ
);

CREATE OR REPLACE TRIGGER trg_applications_updated_at
    BEFORE UPDATE ON applications
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE IF NOT EXISTS application_redirect_uris (
    id              SERIAL          PRIMARY KEY,
    application_id  INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    kind            VARCHAR(8)      NOT NULL CHECK (kind IN ('login', 'logout')),
    uri             VARCHAR(500)    NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS application_redirect_uris_unique ON application_redirect_uris (application_id, kind, uri);

CREATE TABLE IF NOT EXISTS application_origins (
    id              SERIAL          PRIMARY KEY,
    application_id  INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    origin          VARCHAR(255)    NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS application_origins_unique ON application_origins (application_id, origin);
CREATE INDEX IF NOT EXISTS application_origins_origin ON application_origins (origin);

-----------------------------------------------------------
-- ROLES
--
-- Roles belong to an application: genshin's EDITOR means nothing to the piano
-- site. A token names the roles for the one application it is for and no
-- others, so a site never learns what somebody may do anywhere else.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS application_roles (
    id              SERIAL          PRIMARY KEY,
    application_id  INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    name            VARCHAR(50)     NOT NULL CHECK (name ~ '^[A-Z][A-Z0-9_]{0,49}$'),
    description     VARCHAR(255)
);

CREATE UNIQUE INDEX IF NOT EXISTS application_roles_unique ON application_roles (application_id, name);

CREATE TABLE IF NOT EXISTS user_roles (
    user_id     INT             NOT NULL REFERENCES users(id),
    role_id     INT             NOT NULL REFERENCES application_roles(id) ON DELETE CASCADE,
    granted_by  INT             REFERENCES users(id) ON DELETE SET NULL,
    granted_at  TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id)
);

CREATE INDEX IF NOT EXISTS user_roles_role ON user_roles (role_id);

-----------------------------------------------------------
-- AUTHORIZATION REQUESTS
--
-- What an application asked for when it sent somebody here, kept while they
-- sign in. The browser only ever carries the id - a random 128 bits - so the
-- redirect URI, the PKCE challenge and the rest cannot be swapped on the way
-- through the sign-in pages.
--
-- A logout an application asked for without an ID token to prove it is kept
-- the same way, as kind 'logout', until the person confirms it.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS auth_requests (
    id                      CHAR(32)        PRIMARY KEY,
    kind                    VARCHAR(8)      NOT NULL DEFAULT 'login' CHECK (kind IN ('login', 'logout')),
    application_id          INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    redirect_uri            VARCHAR(500),
    scope                   VARCHAR(255),
    state                   VARCHAR(512),
    nonce                   VARCHAR(512),
    code_challenge          VARCHAR(128),
    code_challenge_method   VARCHAR(8),
    prompt                  VARCHAR(32),
    login_hint              VARCHAR(255),
    max_age                 INT,
    ui_locales              VARCHAR(32),
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at              TIMESTAMPTZ     NOT NULL,
    completed_at            TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS auth_requests_expires ON auth_requests (expires_at);

-----------------------------------------------------------
-- AUTHORIZATION CODES
--
-- Good once, for sixty seconds, and only with the verifier behind the PKCE
-- challenge it was issued against. The code itself is 256 random bits and only
-- its hash is stored. Consumed by an UPDATE that carries the condition, so two
-- requests racing with the same code cannot both win.
--
-- `family_id` is the refresh-token family the code turned into. A code
-- presented a second time means somebody else has it too, and RFC 6749 asks
-- for what it produced to be revoked - this is how that family is found.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS auth_codes (
    id                      SERIAL          PRIMARY KEY,
    code_hash               CHAR(64)        NOT NULL,
    application_id          INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    user_id                 INT             NOT NULL REFERENCES users(id),
    session_id              INT             NOT NULL REFERENCES sessions(id),
    redirect_uri            VARCHAR(500)    NOT NULL,
    scope                   VARCHAR(255)    NOT NULL,
    nonce                   VARCHAR(512),
    code_challenge          VARCHAR(128),
    code_challenge_method   VARCHAR(8),
    family_id               CHAR(32),
    expires_at              TIMESTAMPTZ     NOT NULL,
    consumed_at             TIMESTAMPTZ,
    created_at              TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE UNIQUE INDEX IF NOT EXISTS auth_codes_hash ON auth_codes (code_hash);

-----------------------------------------------------------
-- REFRESH TOKENS, AND TOKENS FOR SCRIPTS
--
-- A rotating refresh token is good once: using it spends it and issues its
-- successor, all in one `family`. A spent token presented again means two
-- parties hold the family - the owner and somebody who copied it - and the
-- whole family is ended, except within a minute of the rotation, which is two
-- tabs of the same browser racing rather than theft.
--
-- A personal token is what somebody makes on the account page for a script:
-- named, bound to one application, never rotated, good until it expires or is
-- revoked. It carries no session - it outlives any one sign-in on purpose.
--
-- Only hashes are stored. `session_id` ties a rotating token to the sign-in it
-- was issued under, which is how signing out ends it.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id              SERIAL          PRIMARY KEY,
    token_hash      CHAR(64)        NOT NULL,
    kind            VARCHAR(10)     NOT NULL CHECK (kind IN ('rotating', 'personal')),
    family_id       CHAR(32)        NOT NULL,
    application_id  INT             NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
    user_id         INT             NOT NULL REFERENCES users(id),
    session_id      INT             REFERENCES sessions(id),
    scope           VARCHAR(255)    NOT NULL,
    name            VARCHAR(100),
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      TIMESTAMPTZ     NOT NULL,
    used_at         TIMESTAMPTZ,
    replaced_by     INT             REFERENCES refresh_tokens(id),
    last_used_at    TIMESTAMPTZ,
    revoked_at      TIMESTAMPTZ,
    revoked_reason  VARCHAR(32),
    CHECK (kind = 'rotating' OR name IS NOT NULL)
);

CREATE UNIQUE INDEX IF NOT EXISTS refresh_tokens_hash ON refresh_tokens (token_hash);
CREATE INDEX IF NOT EXISTS refresh_tokens_family ON refresh_tokens (family_id);
CREATE INDEX IF NOT EXISTS refresh_tokens_session ON refresh_tokens (session_id);
CREATE INDEX IF NOT EXISTS refresh_tokens_user ON refresh_tokens (user_id, kind);

-----------------------------------------------------------
-- SIGNING KEYS
--
-- RSA key pairs for RS256. The public half is what the JWKS publishes; the
-- private half is stored encrypted with the master key, so neither a copy of
-- this table nor a database dump is enough to mint a token.
--
-- One key is active and signs everything. Rotating makes a new active key and
-- retires the old one, which stays in the JWKS a day so tokens it already
-- signed keep verifying until they expire.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS signing_keys (
    id              SERIAL          PRIMARY KEY,
    kid             VARCHAR(64)     NOT NULL UNIQUE,
    alg             VARCHAR(10)     NOT NULL DEFAULT 'RS256' CHECK (alg = 'RS256'),
    public_n        TEXT            NOT NULL,
    public_e        TEXT            NOT NULL,
    private_key     TEXT            NOT NULL,
    status          VARCHAR(10)     NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'retired')),
    created_at      TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    retired_at      TIMESTAMPTZ
);

-- At most one active key: two would leave "which one signs" to the order a
-- query happened to return them in.
CREATE UNIQUE INDEX IF NOT EXISTS signing_keys_one_active ON signing_keys (status) WHERE status = 'active';

-----------------------------------------------------------
-- SWITCHES
--
-- The decisions an admin makes without a deploy, as rows: a name, a declared
-- type, a value as text. A new switch is a row in a migration.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS settings (
    name        VARCHAR(64)     PRIMARY KEY,
    group_name  VARCHAR(32)     NOT NULL,
    type        VARCHAR(16)     NOT NULL CHECK (type IN ('boolean', 'text', 'choice')),
    options     VARCHAR(255),
    value       TEXT            NOT NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    updated_at  TIMESTAMPTZ,
    updated_by  INT             REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO settings (name, group_name, type, value, sort_order) VALUES
    ('login_enabled', 'access', 'boolean', 'true', 10),
    ('registration_enabled', 'access', 'boolean', 'true', 20),
    ('google_login_enabled', 'access', 'boolean', 'true', 30)
ON CONFLICT (name) DO NOTHING;

-----------------------------------------------------------
-- WHAT THE ADMINS DID
--
-- Every change made from the admin console, and the security-relevant ones an
-- account makes to itself: who, what, to what, from where. Kept, because the
-- question "who gave this person ADMIN" is only answerable if the row is still
-- there.
-----------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
    id           BIGSERIAL       PRIMARY KEY,
    actor_id     INT             REFERENCES users(id) ON DELETE SET NULL,
    action       VARCHAR(64)     NOT NULL,
    target_type  VARCHAR(32),
    target_id    VARCHAR(64),
    details      JSONB,
    ip           VARCHAR(45),
    created_at   TIMESTAMPTZ     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS audit_log_created ON audit_log (created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_actor ON audit_log (actor_id, created_at DESC);
CREATE INDEX IF NOT EXISTS audit_log_target ON audit_log (target_type, target_id);
