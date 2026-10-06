// anotoki-auth - the browser client for the anotoki IAM.            v1.2.0
//
// OpenID Connect, authorization code flow with PKCE, for a site whose frontend
// talks to its own backend with a bearer token. No dependencies and no build
// step: copy this file (or anotoki-auth.js, the classic-script copy of it, or
// anotoki-auth.d.ts for the types) into the application.
//
// What it keeps, and where:
//
//   localStorage   anotoki-auth:{clientId}  the tokens and who they belong to.
//                  Shared by every tab and every page of the site, so a
//                  multi-page site is signed in on all of them at once.
//   sessionStorage anotoki-auth:{clientId}:login:{state}  the PKCE verifier and
//                  nonce of a sign-in in progress, until its callback lands.
//
// Refresh tokens rotate: each one is good once. Two tabs refreshing at the same
// moment would spend the same token twice, which the IAM reads as theft and
// answers by ending the session - so refreshes are serialised across tabs with
// the Web Locks API, and a tab that waited re-reads what the other one got
// instead of refreshing again.
//
// The ID token's claims are checked (issuer, audience, nonce, expiry); its
// signature is not. It arrives straight from the token endpoint over TLS, and it
// only tells this page who signed in - the backend verifies the access token,
// which is the one that grants anything.
//
// The account's preferences - the theme, the dates, the clock and the language,
// which every anotoki site follows - come with who signed in (user().preferences,
// user().locale), and savePreferences() changes them from the site, for every
// site at once.

const SDK_VERSION = '1.2.0';

/** What the IAM may say for each preference; anything else reads as the default. */
const THEMES = ['auto', 'light', 'dark'];
const DATE_FORMATS = ['dmy_dot', 'dmy_slash', 'mdy_slash', 'ymd_dash'];
const TIME_FORMATS = ['24', '12'];

const DEFAULT_SCOPE = 'openid profile email';

/** Refresh when less than this is left on the access token. */
const REFRESH_MARGIN_MS = 30_000;

/** How long a finished discovery document is reused before being asked for again. */
const DISCOVERY_TTL_MS = 3_600_000;

/** A sign-in that has not come back within this long is abandoned. */
const PENDING_TTL_MS = 3_600_000;

/**
 * Slack on the ID token's expiry, for a device whose clock is a few minutes out.
 * Wider than a backend would allow, because a phone's clock is not a server's.
 */
const CLOCK_LEEWAY_S = 600;

function createAuth(options) {
  const issuer = String(options?.issuer ?? '').replace(/\/+$/, '');
  const clientId = String(options?.clientId ?? '');
  const redirectUri = String(options?.redirectUri ?? '');

  if (!issuer || !clientId || !redirectUri) {
    throw new Error('anotoki-auth: issuer, clientId and redirectUri are required');
  }

  const scope = options.scope ?? DEFAULT_SCOPE;
  const postLogoutRedirectUri = options.postLogoutRedirectUri ?? null;
  const storageKey = options.storageKey ?? `anotoki-auth:${clientId}`;
  const pendingPrefix = `${storageKey}:login:`;
  const discoveryKey = `anotoki-auth:discovery:${issuer}`;
  const lockName = `anotoki-auth-refresh:${storageKey}`;

  const listeners = new Set();
  let session = readSession() ?? null;
  let refreshing = null;

  // Another tab signed in, refreshed or signed out: pick up what it wrote.
  if (typeof window !== 'undefined') {
    window.addEventListener('storage', (event) => {
      if (event.key === storageKey) {
        session = readSession() ?? null;
        notify();
      }
    });
  }

  // ── storage ────────────────────────────────────────────────────────────────

  /**
   * What is stored, null for nothing - or undefined when storage cannot be read
   * at all, which callers take as "keep what this page already has in memory".
   */
  function readSession() {
    try {
      const raw = localStorage.getItem(storageKey);
      const parsed = raw ? JSON.parse(raw) : null;
      if (!parsed || typeof parsed !== 'object') {
        return null;
      }
      // Kept by a copy of the SDK from before preferences: none known yet,
      // and the next refresh brings them.
      if (parsed.user && typeof parsed.user === 'object' && !('preferences' in parsed.user)) {
        parsed.user.preferences = null;
      }
      return parsed;
    } catch {
      return undefined;
    }
  }

  function writeSession(next) {
    session = next;
    try {
      if (next) {
        localStorage.setItem(storageKey, JSON.stringify(next));
      } else {
        localStorage.removeItem(storageKey);
      }
    } catch {
      // A browser refusing storage (private mode on some engines) still gets a
      // working session for this page; it just will not survive a reload.
    }
    notify();
  }

  function notify() {
    for (const listener of listeners) {
      try {
        listener();
      } catch (error) {
        console.error('anotoki-auth: a change listener threw', error);
      }
    }
  }

  // ── discovery ──────────────────────────────────────────────────────────────

  // The IAM's own paths, used when discovery cannot be reached. Refreshing an
  // existing session should not depend on one more request succeeding.
  function defaultEndpoints() {
    return {
      authorization_endpoint: `${issuer}/api/oidc/authorize`,
      token_endpoint: `${issuer}/api/oidc/token`,
      end_session_endpoint: `${issuer}/api/oidc/logout`,
      revocation_endpoint: `${issuer}/api/oidc/revoke`,
      userinfo_endpoint: `${issuer}/api/oidc/userinfo`,
    };
  }

  async function endpoints() {
    try {
      const cached = JSON.parse(sessionStorage.getItem(discoveryKey) ?? 'null');
      if (cached && cached.expiresAt > Date.now()) {
        return cached.document;
      }
    } catch {
      // Fall through to asking.
    }

    try {
      const response = await fetch(`${issuer}/.well-known/openid-configuration`);
      if (response.ok) {
        const document = await response.json();
        if (document.issuer === issuer) {
          try {
            sessionStorage.setItem(discoveryKey, JSON.stringify({ document, expiresAt: Date.now() + DISCOVERY_TTL_MS }));
          } catch {
            // Not cached; asked again next time.
          }
          return document;
        }
        console.warn('anotoki-auth: discovery names a different issuer; using the default endpoints');
      }
    } catch {
      // Offline, or the IAM is down: the defaults are right for this IAM.
    }

    return defaultEndpoints();
  }

  // ── encoding and crypto ────────────────────────────────────────────────────

  function base64Url(bytes) {
    let binary = '';
    for (const byte of bytes) {
      binary += String.fromCharCode(byte);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function randomString(byteCount) {
    const bytes = new Uint8Array(byteCount);
    crypto.getRandomValues(bytes);
    return base64Url(bytes);
  }

  async function sha256(text) {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return new Uint8Array(digest);
  }

  function decodeJwtPayload(jwt) {
    const part = String(jwt ?? '').split('.')[1];
    if (!part) {
      return null;
    }
    try {
      const padded = part.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((part.length + 3) % 4);
      const binary = atob(padded);
      const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
      return JSON.parse(new TextDecoder().decode(bytes));
    } catch {
      return null;
    }
  }

  // ── returnTo ───────────────────────────────────────────────────────────────

  // Only a path on this site. Anything else - another origin, a protocol-relative
  // "//evil", a javascript: URL - would make the callback an open redirect.
  //
  // Judged as the browser will read it: the URL parser drops tabs and line
  // breaks and reads a backslash as a slash, so "/\t/evil" and "/\\evil" are
  // "//evil" by the time they are followed. No control character or backslash
  // is let through, and what is left must resolve to this origin with a path
  // that does not itself start "//" ("/.//evil" normalises to that). The text
  // is handed back as it came, never the normalised form.
  function safeReturnTo(value) {
    const text = typeof value === 'string' ? value : '';
    if (!text.startsWith('/') || /[\u0000-\u001f\u007f\\]/.test(text)) {
      return '/';
    }
    try {
      const url = new URL(text, location.origin);
      return url.origin === location.origin && !url.pathname.startsWith('//') ? text : '/';
    } catch {
      return '/';
    }
  }

  function currentPath() {
    return location.pathname + location.search + location.hash;
  }

  // ── the ID token, and who it says is signed in ─────────────────────────────

  function checkIdToken(idToken, expectedNonce) {
    const claims = decodeJwtPayload(idToken);
    const now = Math.floor(Date.now() / 1000);

    if (!claims) {
      throw authError('invalid_id_token', 'The sign-in answer could not be read');
    }
    if (claims.iss !== issuer) {
      throw authError('invalid_id_token', 'The sign-in answer came from a different issuer');
    }
    const audience = Array.isArray(claims.aud) ? claims.aud : [claims.aud];
    if (!audience.includes(clientId) || (audience.length > 1 && claims.azp !== clientId)) {
      throw authError('invalid_id_token', 'The sign-in answer was meant for a different site');
    }
    if (typeof claims.exp !== 'number' || claims.exp + CLOCK_LEEWAY_S < now) {
      throw authError('invalid_id_token', 'The sign-in answer has expired - is this device\'s clock right?');
    }
    if (expectedNonce !== undefined && claims.nonce !== expectedNonce) {
      throw authError('invalid_id_token', 'The sign-in answer does not belong to this sign-in');
    }

    return claims;
  }

  function userFrom(claims) {
    return {
      sub: String(claims.sub),
      preferred_username: claims.preferred_username ?? null,
      email: claims.email ?? null,
      email_verified: claims.email_verified === true,
      locale: claims.locale ?? null,
      roles: Array.isArray(claims.roles) ? claims.roles.map(String) : [],
      preferences: preferencesFrom(claims.preferences),
    };
  }

  /**
   * The account's theme, date order and clock, or null when the IAM sends
   * none - an IAM from before preferences, or a scope without `profile` - and
   * the site keeps to its own. A value this copy of the SDK does not know (a
   * newer IAM's) reads as the default: "auto", or null for "automatic".
   */
  function preferencesFrom(value) {
    if (!value || typeof value !== 'object') {
      return null;
    }
    return {
      theme: THEMES.includes(value.theme) ? value.theme : 'auto',
      date_format: DATE_FORMATS.includes(value.date_format) ? value.date_format : null,
      time_format: TIME_FORMATS.includes(value.time_format) ? value.time_format : null,
    };
  }

  function sessionFrom(tokens, previous, expectedNonce) {
    const user = tokens.id_token
      ? userFrom(checkIdToken(tokens.id_token, expectedNonce))
      : previous?.user ?? null;

    return {
      access_token: tokens.access_token,
      access_expires_at: Date.now() + Number(tokens.expires_in ?? 0) * 1000,
      // A refresh answer without one (a personal token never rotates, and an
      // IAM may choose not to) keeps the one it was given.
      refresh_token: tokens.refresh_token ?? previous?.refresh_token ?? null,
      id_token: tokens.id_token ?? previous?.id_token ?? null,
      user,
      updated_at: Date.now(),
    };
  }

  function authError(code, message) {
    const error = new Error(message);
    error.code = code;
    return error;
  }

  // ── the token endpoint ─────────────────────────────────────────────────────

  async function tokenRequest(parameters) {
    const { token_endpoint } = await endpoints();
    const response = await fetch(token_endpoint, {
      method: 'POST',
      // A form body keeps this a "simple" CORS request: no preflight, one
      // round trip.
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ client_id: clientId, ...parameters }),
    });

    let body = null;
    try {
      body = await response.json();
    } catch {
      body = null;
    }

    if (!response.ok) {
      throw authError(body?.error ?? `http_${response.status}`, body?.error_description ?? 'The IAM refused the request');
    }

    return body;
  }

  function accessTokenFresh(current, margin = REFRESH_MARGIN_MS) {
    return Boolean(current?.access_token) && current.access_expires_at - margin > Date.now();
  }

  /**
   * One refresh at a time, across every tab.
   *
   * `refusedToken` is the access token an API has just answered 401 to. It is
   * refreshed even though it looks fresh - a revoked session or a rotated key
   * does not show on the token itself - unless another tab has meanwhile got a
   * different one, which is then the answer.
   */
  async function refresh(refusedToken = null) {
    if (refreshing) {
      return refreshing;
    }

    const run = async () => {
      // Another tab may have refreshed, or signed out, while this one waited
      // for the lock. What it wrote is newer than what this page holds.
      const stored = readSession();
      if (stored !== undefined) {
        session = stored;
      }
      if (accessTokenFresh(session) && session.access_token !== refusedToken) {
        return session.access_token;
      }
      if (!session?.refresh_token) {
        return null;
      }

      try {
        const tokens = await tokenRequest({ grant_type: 'refresh_token', refresh_token: session.refresh_token });
        writeSession(sessionFrom(tokens, session));
        return session.access_token;
      } catch (error) {
        // The IAM said no: the session is over (signed out elsewhere, account
        // disabled, access taken away). Anything else - no network, a 500 -
        // leaves the session where it is to be tried again.
        if (error.code === 'invalid_grant' || error.code === 'invalid_client') {
          writeSession(null);
        }
        return null;
      }
    };

    refreshing = (typeof navigator !== 'undefined' && navigator.locks?.request
      ? navigator.locks.request(lockName, run)
      : run()
    ).finally(() => {
      refreshing = null;
    });

    return refreshing;
  }

  // ── the callback ───────────────────────────────────────────────────────────

  function isCallback() {
    const target = new URL(redirectUri, location.href);
    const params = new URLSearchParams(location.search);
    return location.origin === target.origin
      && location.pathname === target.pathname
      && params.has('state')
      && (params.has('code') || params.has('error'));
  }

  function takePending(state) {
    const key = pendingPrefix + state;
    let pending = null;
    try {
      pending = JSON.parse(sessionStorage.getItem(key) ?? 'null');
      sessionStorage.removeItem(key);
    } catch {
      pending = null;
    }
    return pending && pending.createdAt > Date.now() - PENDING_TTL_MS ? pending : null;
  }

  async function finishCallback() {
    const params = new URLSearchParams(location.search);
    const pending = takePending(params.get('state') ?? '');

    if (!pending) {
      return { returnTo: '/', error: authError('state_mismatch', 'That sign-in did not start here, or took too long') };
    }

    const returnTo = safeReturnTo(pending.returnTo);

    if (params.has('error')) {
      return {
        returnTo,
        error: authError(params.get('error'), params.get('error_description') ?? 'The sign-in did not complete'),
      };
    }

    // RFC 9207: the answer names who sent it, so one IAM's code cannot be
    // passed off as another's.
    if (params.has('iss') && params.get('iss') !== issuer) {
      return { returnTo, error: authError('invalid_issuer', 'The sign-in answer came from a different issuer') };
    }

    try {
      const tokens = await tokenRequest({
        grant_type: 'authorization_code',
        code: params.get('code') ?? '',
        redirect_uri: redirectUri,
        code_verifier: pending.verifier,
      });
      writeSession(sessionFrom(tokens, null, pending.nonce));
      return { returnTo, error: null };
    } catch (error) {
      return { returnTo, error };
    }
  }

  // ── the public surface ─────────────────────────────────────────────────────

  async function init() {
    if (isCallback()) {
      const result = await finishCallback();
      // The code and state are spent; they should not sit in the address bar,
      // the history or a bookmark.
      history.replaceState(null, '', result.returnTo);
      return result;
    }

    if (session && !accessTokenFresh(session)) {
      await refresh();
    }

    return { returnTo: null, error: null };
  }

  async function login(parameters = {}) {
    const state = randomString(16);
    const nonce = randomString(16);
    const verifier = randomString(32);
    const challenge = base64Url(await sha256(verifier));

    try {
      sessionStorage.setItem(pendingPrefix + state, JSON.stringify({
        verifier,
        nonce,
        returnTo: safeReturnTo(parameters.returnTo ?? currentPath()),
        createdAt: Date.now(),
      }));
    } catch {
      throw authError('storage_unavailable', 'This browser will not let the site remember the sign-in in progress');
    }

    const { authorization_endpoint } = await endpoints();
    const url = new URL(authorization_endpoint);
    const query = {
      response_type: 'code',
      client_id: clientId,
      redirect_uri: redirectUri,
      scope,
      state,
      nonce,
      code_challenge: challenge,
      code_challenge_method: 'S256',
    };
    if (parameters.prompt) query.prompt = parameters.prompt;
    if (parameters.loginHint) query.login_hint = parameters.loginHint;
    if (parameters.uiLocales) query.ui_locales = parameters.uiLocales;
    if (parameters.maxAge !== undefined) query.max_age = String(parameters.maxAge);

    for (const [key, value] of Object.entries(query)) {
      url.searchParams.set(key, value);
    }

    location.assign(url.toString());
  }

  function register(parameters = {}) {
    return login({ ...parameters, prompt: 'create' });
  }

  /**
   * Signs out.
   *
   * By default everywhere: the IAM ends its session, which spends every refresh
   * token issued under it on every site, and the browser lands on
   * `postLogoutRedirectUri` (or the IAM's own "signed out" page without one).
   * The landing address is always the configured one - the IAM accepts only a
   * registered address, byte for byte.
   *
   * `{ local: true }` signs out of this site only and does not navigate.
   */
  async function logout(parameters = {}) {
    const previous = session;
    writeSession(null);

    if (parameters.local) {
      // This site only. The refresh token is spent so it cannot be used again;
      // the IAM session - and so every other site - stays signed in.
      if (previous?.refresh_token) {
        try {
          const { revocation_endpoint } = await endpoints();
          await fetch(revocation_endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ client_id: clientId, token: previous.refresh_token, token_type_hint: 'refresh_token' }),
            keepalive: true,
          });
        } catch {
          // Best effort: the token dies with its IAM session regardless.
        }
      }
      return;
    }

    const { end_session_endpoint } = await endpoints();
    const url = new URL(end_session_endpoint);
    url.searchParams.set('client_id', clientId);
    if (previous?.id_token) url.searchParams.set('id_token_hint', previous.id_token);
    if (postLogoutRedirectUri) url.searchParams.set('post_logout_redirect_uri', postLogoutRedirectUri);

    location.assign(url.toString());
  }

  async function getAccessToken() {
    if (accessTokenFresh(session)) {
      return session.access_token;
    }
    return session?.refresh_token ? refresh() : null;
  }

  async function authFetch(input, init = {}) {
    const token = await getAccessToken();
    const send = (bearer) => {
      const headers = new Headers(init.headers ?? {});
      if (bearer) {
        headers.set('Authorization', `Bearer ${bearer}`);
      }
      return fetch(input, { ...init, headers });
    };

    const response = await send(token);
    if (response.status !== 401 || !token) {
      return response;
    }

    // One retry, and only with a token that is actually different. A body
    // that is a stream cannot be sent twice; everything this SDK is used for
    // sends strings or FormData.
    const renewed = await refresh(token);
    return renewed && renewed !== token ? send(renewed) : response;
  }

  /**
   * Changes the account's preferences - for every anotoki site, not just this
   * one. `changes` holds any of theme ("auto", "light", "dark"), date_format
   * (one of the four orders, or null for automatic), time_format ("24", "12",
   * or null) and language (a code the IAM offers). Resolves to the account's
   * preferences and locale as they now stand, and puts them in user() at once,
   * in every tab; the tokens carry them from their next refresh. Rejects with
   * the IAM's error code: "login_required" when nobody is signed in,
   * "insufficient_scope" for a sign-in without `profile`, "invalid_request"
   * for a value the IAM does not take, "access_denied" when the site no
   * longer lets the person in, "network" when the IAM cannot be reached.
   */
  async function savePreferences(changes) {
    const sub = session?.user?.sub;
    if (!sub) {
      throw authError('login_required', 'Nobody is signed in');
    }

    // Only ever with this person's own token. Another tab may sign somebody
    // else in while this one waits for a token, and a refresh takes up what
    // that tab stored: a token that is no longer this person's is not sent,
    // or the change would land on the other account.
    const ownToken = async (refused) => {
      const token = await (refused ? refresh(refused) : getAccessToken());
      if (session?.user?.sub !== sub) {
        throw authError('login_required', session?.user ? 'Somebody else signed in meanwhile' : 'Nobody is signed in');
      }
      return token;
    };
    const send = async (token) => {
      try {
        return await fetch(`${issuer}/api/oidc/preferences`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          body: JSON.stringify(changes ?? {}),
        });
      } catch {
        throw authError('network', 'The IAM could not be reached');
      }
    };

    const token = await ownToken(null);
    if (!token) {
      throw authError('network', 'The IAM could not be reached');
    }
    let response = await send(token);
    if (response.status === 401) {
      // One retry, and only with a token that is actually different - as fetch() does.
      const renewed = await ownToken(token);
      if (renewed && renewed !== token) {
        response = await send(renewed);
      }
    }

    let body = null;
    try {
      body = await response.json();
    } catch {
      body = null;
    }
    if (!response.ok) {
      throw authError(body?.error ?? `http_${response.status}`, body?.error_description ?? 'The IAM refused the change');
    }

    const saved = { locale: body?.locale ?? null, preferences: preferencesFrom(body?.preferences) };
    // Written over what is stored now - unless this tab or another signed out,
    // or somebody else signed in, while the change was on its way: then the
    // answer belongs to nobody on the page. Under the refresh lock: what is
    // written back holds the refresh token too, and one another tab rotates
    // between the read and the write would put a spent token back - whose next
    // use the IAM takes for theft, and ends the session.
    const merge = async () => {
      const stored = readSession();
      const current = stored === undefined ? session : stored;
      if (current?.user?.sub === sub) {
        writeSession({ ...current, user: { ...current.user, ...saved } });
      }
    };
    await (typeof navigator !== 'undefined' && navigator.locks?.request ? navigator.locks.request(lockName, merge) : merge());
    return saved;
  }

  function onChange(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
  }

  return {
    version: SDK_VERSION,
    init,
    login,
    register,
    logout,
    getAccessToken,
    // For an HTTP layer that is not this fetch (an Angular interceptor): the API
    // refused the token it was given, so get a different one - or null.
    refresh: () => refresh(session?.access_token ?? null),
    fetch: authFetch,
    savePreferences,
    onChange,
    isAuthenticated: () => Boolean(session?.refresh_token || accessTokenFresh(session, 0)),
    user: () => session?.user ?? null,
    roles: () => session?.user?.roles ?? [],
    hasRole: (role) => (session?.user?.roles ?? []).includes(role),
    accountUrl: () => `${issuer}/account`,
    issuer: () => issuer,
    clientId: () => clientId,
  };
}

export { createAuth, SDK_VERSION as version };
