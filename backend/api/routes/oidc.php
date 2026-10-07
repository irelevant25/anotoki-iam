<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// ---------------------------------------------------------------------------
// OpenID Connect - the endpoints the sites use
//
// See docs/api.md for the contract. Everything an application needs is here:
// discovery, the public keys, authorize, token, userinfo, logout and
// revocation - and, anotoki's own, a site's save of its reader's preferences,
// whether a site requires signing in, and the ids of the deleted accounts.
// The IAM's own pages (routes/login.php) finish what authorize cannot do
// alone - showing a form.
// ---------------------------------------------------------------------------

/** OAuth's error shape, as the token, userinfo and revocation endpoints must answer. */
function oauthError(Response $response, int $status, string $error, string $description): Response
{
    return respondJson($response, ['error' => $error, 'error_description' => $description], $status)
        ->withHeader('Cache-Control', 'no-store')
        ->withHeader('Pragma', 'no-cache');
}

function redirectTo(Response $response, string $location): Response
{
    return $response->withStatus(302)->withHeader('Location', $location);
}

/**
 * Who is calling the token or revocation endpoint.
 *
 * Every application is a public client (migration 011, the owner's decision):
 * it names itself with client_id, and a sign-in's code is proved with PKCE.
 * There are no client secrets, so a request that carries one - by HTTP Basic
 * or as client_secret in the body - is refused (clientSentSecret()) rather
 * than half-read: a secret sent means the caller expects a check the IAM no
 * longer makes. An unknown, switched-off or system client is the same answer
 * as any other failure.
 *
 * @return array|null the application
 */
function authenticateClient(Request $request, array $body): ?array
{
    $application = applicationByClientId(field($body, 'client_id'));

    if ($application === null || asBool($application['system']) || !asBool($application['enabled'])) {
        return null;
    }

    return $application;
}

/** Whether a request to the token or revocation endpoint carries a client secret (HTTP Basic or client_secret). */
function clientSentSecret(Request $request, array $body): bool
{
    return array_key_exists('client_secret', $body) || strncasecmp($request->getHeaderLine('Authorization'), 'Basic', 5) === 0;
}

const PUBLIC_CLIENTS_ONLY = 'Public clients only - send client_id and the PKCE code_verifier, no secret';

/**
 * A browser calling the token endpoint must be on one of the client's own
 * origins. Not a boundary on its own - anything outside a browser can send any
 * Origin - but it keeps one site's page from spending another site's tokens.
 */
function originAllowedFor(Request $request, array $application): bool
{
    $origin = $request->getHeaderLine('Origin');

    return $origin === '' || in_array($origin, applicationOrigins((int) $application['id']), true);
}

// ── Discovery ────────────────────────────────────────────────────────────────

$app->get('/.well-known/openid-configuration', function (Request $request, Response $response) {
    $issuer = iamIssuer();

    return respondJson($response, [
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer . '/api/oidc/authorize',
        'token_endpoint' => $issuer . '/api/oidc/token',
        'userinfo_endpoint' => $issuer . '/api/oidc/userinfo',
        'jwks_uri' => $issuer . '/.well-known/jwks.json',
        'end_session_endpoint' => $issuer . '/api/oidc/logout',
        'revocation_endpoint' => $issuer . '/api/oidc/revoke',
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'subject_types_supported' => ['public'],
        'id_token_signing_alg_values_supported' => ['RS256'],
        // Public clients only (011): nothing authenticates a client but PKCE.
        'token_endpoint_auth_methods_supported' => ['none'],
        'revocation_endpoint_auth_methods_supported' => ['none'],
        'code_challenge_methods_supported' => ['S256'],
        'scopes_supported' => OIDC_SCOPES,
        'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'sid', 'amr',
            'preferred_username', 'email', 'email_verified', 'locale', 'preferences', 'roles'],
        'prompt_values_supported' => ['none', 'login', 'create'],
        'ui_locales_supported' => languageCodes(),
        'authorization_response_iss_parameter_supported' => true,
    ])->withHeader('Cache-Control', 'public, max-age=3600');
});

$app->get('/.well-known/jwks.json', function (Request $request, Response $response) {
    return respondJson($response, publishedKeys())->withHeader('Cache-Control', 'public, max-age=900');
});

// ── Authorize ────────────────────────────────────────────────────────────────

$app->get('/api/oidc/authorize', function (Request $request, Response $response) {
    $query = $request->getQueryParams();
    $pdo = iamDb();
    iamHousekeeping();

    // Until the client and its redirect URI are known good, nothing is sent to
    // the redirect URI: an unregistered one is exactly where a code must never
    // go. The IAM's own error page says what was wrong instead.
    $application = applicationByClientId(field($query, 'client_id'));
    $redirectUri = field($query, 'redirect_uri');

    if ($application === null || asBool($application['system'])) {
        return redirectTo($response, iamPageUrl('/error', ['code' => 'invalid_client']));
    }
    if (!redirectUriRegistered((int) $application['id'], 'login', $redirectUri)) {
        return redirectTo($response, iamPageUrl('/error', ['code' => 'invalid_redirect_uri']));
    }
    if (!asBool($application['enabled'])) {
        return redirectTo($response, iamPageUrl('/error', ['code' => 'application_disabled']));
    }

    $state = field($query, 'state');
    $back = ['redirect_uri' => $redirectUri, 'state' => $state];

    $prompts = array_values(array_filter(explode(' ', field($query, 'prompt'))));
    $challenge = field($query, 'code_challenge');
    $challengeMethod = field($query, 'code_challenge_method');
    $scope = grantedScope(field($query, 'scope'));
    $maxAge = field($query, 'max_age');

    $invalid = match (true) {
        field($query, 'response_type') !== 'code' => ['unsupported_response_type', 'Only response_type=code is supported'],
        $state === '' || strlen($state) > 512 => ['invalid_request', 'state is required (at most 512 characters)'],
        strlen(field($query, 'nonce')) > 512 => ['invalid_request', 'nonce is too long'],
        !in_array('openid', explode(' ', $scope), true) => ['invalid_scope', 'The scope must include openid'],
        $challenge === '' => ['invalid_request', 'A public client must send a PKCE code_challenge'],
        $challenge !== '' && ($challengeMethod !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/', $challenge))
            => ['invalid_request', 'code_challenge must be an S256 challenge'],
        array_diff($prompts, ['none', 'login', 'create', 'consent']) !== [] => ['invalid_request', 'Unknown prompt value'],
        in_array('none', $prompts, true) && count($prompts) > 1 => ['invalid_request', 'prompt=none cannot be combined'],
        $maxAge !== '' && !ctype_digit($maxAge) => ['invalid_request', 'max_age must be a number of seconds'],
        default => null,
    };

    if ($invalid !== null) {
        return redirectTo($response, errorRedirect($back, $invalid[0], $invalid[1]));
    }

    // The first tag the IAM offers: as a whole ("pt-BR" for pt-br), else by
    // its first subtag ("sk-SK" for sk). The site's order of preference is
    // kept - a later tag is only looked at when an earlier one is no language here.
    $language = null;
    $offered = languageCodes();
    foreach (explode(' ', field($query, 'ui_locales')) as $locale) {
        $whole = strtolower($locale);
        foreach ([$whole, explode('-', $whole, 2)[0]] as $candidate) {
            if (in_array($candidate, $offered, true)) {
                $language = $candidate;
                break 2;
            }
        }
    }

    $id = createAuthRequest($pdo, $application, [
        'redirect_uri' => $redirectUri,
        'scope' => $scope,
        'state' => $state,
        'nonce' => field($query, 'nonce') ?: null,
        'code_challenge' => $challenge ?: null,
        'code_challenge_method' => $challenge !== '' ? 'S256' : null,
        'prompt' => $prompts ? implode(' ', $prompts) : null,
        'login_hint' => substr(field($query, 'login_hint'), 0, 255) ?: null,
        'max_age' => $maxAge !== '' ? (int) $maxAge : null,
        'ui_locales' => $language,
    ]);

    $authRequest = liveAuthRequest($pdo, $id);
    $signIn = currentSignIn($request);

    // Single sign-on: already signed in here, and nothing about this request
    // needs a page. Straight back with a code.
    if ($signIn !== null && !in_array('create', $prompts, true)) {
        $user = $signIn['user'];
        $satisfied = sessionSatisfiesRequest($signIn['session'], $authRequest);
        $refusal = applicationAccessRefusal($application, $user);
        $ready = $satisfied && $refusal === null && !asBool($user['force_password_change']);

        if ($ready && completeAuthRequest($pdo, $id)) {
            touchSession($pdo, $signIn['session']);
            return redirectTo($response, issueAuthorizationCode($pdo, $authRequest, $user, $signIn['session']));
        }

        // The account signed in here is being deleted (api/account_deletion.php):
        // nothing goes to any site, and only the IAM can say why and offer the
        // way back - its own page, worded for the person, with the account
        // page a click away, where the deletion is cancelled. Not for
        // prompt=none, which never shows a page; nor when the request asks
        // for credentials again (prompt=login, max_age), which may bring
        // another account - the sign-in page's accept refuses this one the same way.
        if ($satisfied && ($refusal['code'] ?? null) === 'account_deletion_pending' && !in_array('none', $prompts, true)) {
            completeAuthRequest($pdo, $id);
            return redirectTo($response, iamPageUrl('/error', ['code' => 'account_deletion_pending']));
        }
    }

    if (in_array('none', $prompts, true)) {
        completeAuthRequest($pdo, $id);
        // OIDC's error code `login_required` ("nobody is signed in here"), not
        // the application's `login_required` switch the public contract below
        // publishes - the same two words, unrelated.
        $error = $signIn === null ? 'login_required' : 'interaction_required';
        return redirectTo($response, errorRedirect($authRequest, $error, 'Signing in needs a page this request asked not to show'));
    }

    $page = in_array('create', $prompts, true) ? '/register' : '/login';

    return redirectTo($response, iamPageUrl($page, ['request' => $id, 'lang' => $language]));
});

// ── Token ────────────────────────────────────────────────────────────────────

$app->post('/api/oidc/token', function (Request $request, Response $response) {
    $body = requestBody($request);
    if (clientSentSecret($request, $body)) {
        return oauthError($response, 401, 'invalid_client', PUBLIC_CLIENTS_ONLY);
    }
    $application = authenticateClient($request, $body);

    if ($application === null) {
        return oauthError($response, 401, 'invalid_client', 'Client authentication failed');
    }

    if (!originAllowedFor($request, $application)) {
        return oauthError($response, 400, 'invalid_request', 'This origin is not registered for the client');
    }

    $pdo = iamDb();
    $grant = field($body, 'grant_type');
    iamHousekeeping();

    if ($grant === 'authorization_code') {
        $row = redeemAuthorizationCode($pdo, field($body, 'code'), $application, field($body, 'redirect_uri'), field($body, 'code_verifier'));

        if (is_string($row)) {
            return oauthError($response, 400, 'invalid_grant', $row);
        }

        $user = userById($pdo, (int) $row['user_id']);
        $session = sessionById($pdo, (int) $row['session_id']);

        if ($user === null || !sessionLive($session) || applicationAccessRefusal($application, $user) !== null) {
            return oauthError($response, 400, 'invalid_grant', 'The sign-in behind this code is no longer valid');
        }

        $family = randomHex(16);
        $refresh = issueRefreshToken(
            $pdo, 'rotating', $family, (int) $application['id'], (int) $user['id'], (int) $session['id'],
            (string) $row['scope'], secondsUntilSessionEnds($session)
        );
        $pdo->prepare('UPDATE auth_codes SET family_id = ? WHERE id = ?')->execute([$family, (int) $row['id']]);

        return respondJson($response, tokenResponse($application, $user, $session, (string) $row['scope'], $refresh['token'], $row['nonce']))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    if ($grant === 'refresh_token') {
        $result = exchangeRefreshToken($pdo, field($body, 'refresh_token'), $application);

        if (isset($result['error'])) {
            return oauthError($response, 400, 'invalid_grant', $result['error']);
        }

        // A narrower scope may be asked for, never a wider one.
        $scope = (string) $result['row']['scope'];
        if (field($body, 'scope') !== '') {
            $scope = implode(' ', array_values(array_intersect(explode(' ', grantedScope(field($body, 'scope'))), explode(' ', $scope))));
        }

        return respondJson($response, tokenResponse($application, $result['user'], $result['session'], $scope, $result['token']))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    return oauthError($response, 400, 'unsupported_grant_type', 'Supported: authorization_code, refresh_token');
});

// ── Userinfo ─────────────────────────────────────────────────────────────────

/**
 * Who the access token in the Authorization header speaks for - as userinfo
 * and a site's save of its reader's preferences read it, in one place: the
 * token verified (signature, typ, expiry, its session still live), its account
 * switched on, not waiting for approval and not being deleted, its application
 * there. Null for anything less; the caller answers 401 invalid_token.
 *
 * @return array{claims: array, user: array, application: array}|null
 */
function accessTokenHolder(PDO $pdo, Request $request): ?array
{
    $header = $request->getHeaderLine('Authorization');
    $token = strncasecmp($header, 'Bearer ', 7) === 0 ? trim(substr($header, 7)) : '';
    $claims = $token === '' ? null : verifyAccessTokenHere($pdo, $token);
    $user = $claims === null ? null : userById($pdo, (int) $claims['sub']);
    $application = $claims === null ? null : applicationByClientId((string) ($claims['client_id'] ?? $claims['aud'] ?? ''));

    // An account waiting for approval is never issued a token; one that holds
    // one anyway is refused here as it is everywhere else. So is one whose
    // deletion is pending: an access token minted before it was asked for,
    // under the session it kept at the IAM to cancel it, reads nothing here.
    if ($user === null || $application === null || asBool($user['disabled']) || accountAwaitsApproval($user) || accountDeletionPending($user)) {
        return null;
    }

    return ['claims' => $claims, 'user' => $user, 'application' => $application];
}

function invalidAccessToken(Response $response): Response
{
    return oauthError($response, 401, 'invalid_token', 'The access token is not valid')
        ->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
}

$userinfo = function (Request $request, Response $response) {
    $holder = accessTokenHolder(iamDb(), $request);

    if ($holder === null) {
        return invalidAccessToken($response);
    }

    return respondJson($response, ['sub' => (string) $holder['user']['id']]
        + profileClaims($holder['user'], (string) ($holder['claims']['scope'] ?? ''))
        + ['roles' => userRolesIn((int) $holder['user']['id'], (int) $holder['application']['id'])]);
};

$app->get('/api/oidc/userinfo', $userinfo);
$app->post('/api/oidc/userinfo', $userinfo);

// ── A site saves its reader's preferences ────────────────────────────────────

/**
 * POST /api/oidc/preferences {theme?, date_format?, time_format?, language?} -
 * a site saves what its signed-in reader chose with the site's own controls
 * (its theme toggle, its language switcher) to the account, so every site
 * follows it (migration 015; docs/decisions.md, 2026-10-06). Answers the
 * account's `locale` and `preferences` as they are now; every token carries
 * them from its next refresh.
 *
 * The person's access token is the proof, as for userinfo, and it must carry
 * the profile scope these claims are read with. No cookie is read, so it needs
 * no CSRF header - a page elsewhere cannot put the person's token into a
 * request - and it is answered cross-origin to a registered site's origin
 * only, like the token, userinfo and revocation endpoints. It changes nothing
 * but the four, and only the token's own account.
 *
 * Being a write, it asks more of the token than userinfo does (found by the
 * security review): a sign-in behind it - a script's personal token has no
 * reader whose choices there are to save, so a leaked one changes nothing
 * here; the page asking, when one is, being the token's own site's, as at the
 * token endpoint; and the person still allowed into that site - a site
 * switched off, or somebody kept out of it, writes nothing with the tokens
 * still held, by the rules a refresh keeps (applicationAccessRefusal()).
 */
$app->post('/api/oidc/preferences', function (Request $request, Response $response) {
    $pdo = iamDb();
    $holder = accessTokenHolder($pdo, $request);

    if ($holder === null) {
        return invalidAccessToken($response);
    }
    if (!originAllowedFor($request, $holder['application'])) {
        return oauthError($response, 400, 'invalid_request', 'This origin is not registered for the client');
    }
    if (!isset($holder['claims']['sid'])) {
        return oauthError($response, 403, 'access_denied', 'A token made for a script cannot change the preferences');
    }
    $refusal = applicationAccessRefusal($holder['application'], $holder['user']);
    if ($refusal !== null) {
        return oauthError($response, 403, 'access_denied', $refusal['error']);
    }
    if (!in_array('profile', explode(' ', (string) ($holder['claims']['scope'] ?? '')), true)) {
        return oauthError($response, 403, 'insufficient_scope', 'The access token does not carry the profile scope')
            ->withHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="profile"');
    }
    if (!preferencesReady($pdo)) {
        return oauthError($response, 503, 'temporarily_unavailable', 'The preferences are not in the database yet');
    }

    $body = $request->getParsedBody();
    if (!is_array($body) || $body === [] || array_is_list($body)) {
        return oauthError($response, 400, 'invalid_request', 'Send the preferences as a JSON object: {theme?, date_format?, time_format?, language?}');
    }
    $unknown = array_diff(array_map('strval', array_keys($body)), ['theme', 'date_format', 'time_format', 'language']);
    if ($unknown !== []) {
        return oauthError($response, 400, 'invalid_request', 'There is no preference called ' . textClip((string) reset($unknown), 40));
    }
    $checked = preferenceChanges($pdo, $body);
    if (isset($checked['error'])) {
        return oauthError($response, 400, 'invalid_request', $checked['error']);
    }

    $userId = (int) $holder['user']['id'];
    $sets = implode(', ', array_map(static fn(string $column): string => $column . ' = ?', array_keys($checked['changes'])));
    $saved = $pdo->prepare("UPDATE users SET $sets WHERE id = ?" . liveAccountSql($pdo));
    $saved->execute([...array_values($checked['changes']), $userId]);
    // Deleted since the token was read: there is no holder any more.
    if ($saved->rowCount() === 0) {
        return invalidAccessToken($response);
    }
    $fresh = userById($pdo, $userId);

    return respondJson($response, ['locale' => languageOrDefault($fresh['language']), 'preferences' => accountPreferences($fresh)]);
});

// ── Logout ───────────────────────────────────────────────────────────────────

$app->get('/api/oidc/logout', function (Request $request, Response $response) {
    $query = $request->getQueryParams();
    $pdo = iamDb();

    $application = field($query, 'client_id') !== '' ? applicationByClientId(field($query, 'client_id')) : null;
    $hint = field($query, 'id_token_hint') !== '' ? jwtVerifyOwn(field($query, 'id_token_hint'), 'JWT', true) : null;

    if ($hint !== null && $application === null && is_string($hint['aud'] ?? null)) {
        $application = applicationByClientId($hint['aud']);
    }
    if ($hint !== null && ($application === null || ($hint['aud'] ?? null) !== $application['client_id'])) {
        $hint = null;
    }

    // Where to land afterwards - only ever a registered address.
    $landing = null;
    $postLogout = field($query, 'post_logout_redirect_uri');
    if ($postLogout !== '') {
        if ($application === null || !redirectUriRegistered((int) $application['id'], 'logout', $postLogout)) {
            return redirectTo($response, iamPageUrl('/error', ['code' => 'invalid_redirect_uri']));
        }
        $landing = redirectWith($postLogout, ['state' => field($query, 'state')]);
    }

    $signIn = currentSignIn($request);

    if ($signIn === null) {
        return redirectTo($response, $landing ?? iamPageUrl('/logout', ['done' => 1]));
    }

    // The application proved which sign-in it means: end it without asking.
    $proven = $hint !== null
        && hash_equals((string) $signIn['session']['sid'], (string) ($hint['sid'] ?? ''))
        && (string) ($hint['sub'] ?? '') === (string) $signIn['user']['id'];

    if ($proven) {
        revokeSession($pdo, (int) $signIn['session']['id'], SESSION_REVOKED_SIGNOUT);
        return withoutSessionCookie(redirectTo($response, $landing ?? iamPageUrl('/logout', ['done' => 1])));
    }

    // Otherwise a link on somebody else's page could sign people out: ask.
    $owner = $application ?? applicationByClientId(IAM_CLIENT_ID);
    $id = createAuthRequest($pdo, $owner, [
        'redirect_uri' => $postLogout !== '' ? $postLogout : null,
        'state' => field($query, 'state') ?: null,
    ], 'logout');

    return redirectTo($response, iamPageUrl('/logout', ['request' => $id]));
});

// ── Revoke ───────────────────────────────────────────────────────────────────

$app->post('/api/oidc/revoke', function (Request $request, Response $response) {
    $body = requestBody($request);
    if (clientSentSecret($request, $body)) {
        return oauthError($response, 401, 'invalid_client', PUBLIC_CLIENTS_ONLY);
    }
    $application = authenticateClient($request, $body);

    if ($application === null) {
        return oauthError($response, 401, 'invalid_client', 'Client authentication failed');
    }

    // RFC 7009: the same answer whatever the token was, so this cannot be used
    // to ask whether a token exists.
    revokeRefreshToken(iamDb(), field($body, 'token'), $application);

    return respondJson($response, new stdClass());
});

// ── The sites' list ──────────────────────────────────────────────────────────

// Every site that is switched on, in the order the applications were made
// (011: the admin panel no longer has landing page settings - show_on_landing
// is not read, and a new application goes last). The anotoki.eu landing page
// keeps its own list; only its auditor compares with this one.
$app->get('/api/applications', function (Request $request, Response $response) {
    $rows = iamDb()->query(
        'SELECT client_id, name, description, home_url, logo_url FROM applications
          WHERE enabled = TRUE AND system = FALSE
          ORDER BY sort_order, name'
    )->fetchAll();

    return respondJson($response, $rows)->withHeader('Cache-Control', 'public, max-age=300');
});

// ── Is sign-in required here? ────────────────────────────────────────────────

/**
 * The public contract every site's backend reads: whether its application
 * requires signing in (the switch on its page in the admin panel). The PHP
 * SDK's anotoki_iam_login_required() asks it, at most once a minute per site,
 * keeps the answer, and reads anything but a 200 for its own client id as
 * "sign-in required" (a 404 is kept as that; an error falls back to the last
 * answer, or to required when it never had one).
 *
 * Three fields and nothing else, on purpose. 404 for an unknown, malformed,
 * switched-off or system client id - the IAM's own entry is not a site
 * anybody visits. It confirms to anybody who knows a client id that the
 * application exists, and its name - as the sign-in page does for anybody who
 * starts a sign-in to it. In no CORS list: browsers never read it, the sites'
 * servers do. The 404 and the 503s are no-store (the security headers).
 */
$app->get('/api/public/applications/{client_id}', function (Request $request, Response $response, array $args) {
    $clientId = (string) $args['client_id'];
    $application = preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $clientId) ? applicationByClientId($clientId) : null;

    if ($application === null || !asBool($application['enabled']) || asBool($application['system'])) {
        return respondError($response, 404, 'not_found', 'There is no such application');
    }
    // 010 pending: there is no switch to read yet. A 503 makes the SDK keep
    // the answer it had (or require sign-in) until the migration is applied.
    if (!signInRulesReady(iamDb())) {
        $refusal = signInRulesUnavailable();
        return respondError($response, $refusal['status'], $refusal['code'], $refusal['error']);
    }

    return respondJson($response, [
        'client_id' => $application['client_id'],
        'name' => $application['name'],
        'login_required' => asBool($application['login_required']),
    ])->withHeader('Cache-Control', 'public, max-age=60');
});

// ── The accounts that were deleted ───────────────────────────────────────────

/**
 * GET /api/public/anonymized-accounts?since=&after= - the ids of the accounts
 * deleted at their owners' request (api/account_deletion.php), and when, for
 * the sites' backends: each scrubs its own copies of the person's name and
 * address under those ids and keeps the rest of its data, nobody's now (the
 * owner's decision of 2026-10-07).
 *
 * Ids and times and nothing else, in the order they went, 500 at most, with
 * `next` - the last one's time and id - when there may be more. `since` is
 * inclusive: a site asks again from the newest time it has handled, and an id
 * seen twice costs nothing. Before 016 every answer is empty: nobody was
 * deleted then.
 *
 * No session, no token, in no CORS list - a browser never needs it - and
 * cacheable for a minute. It rides housekeeping (the host has no cron), so the
 * sites asking is itself what keeps the deletions on time.
 */
$app->get('/api/public/anonymized-accounts', function (Request $request, Response $response) {
    $query = $request->getQueryParams();
    $sinceText = field($query, 'since');
    $afterText = field($query, 'after');

    $since = $sinceText === '' ? null : anonymizedSince($sinceText);
    if ($sinceText !== '' && $since === null) {
        return respondError($response, 400, 'invalid_request', 'since is an ISO-8601 time, like 2026-11-06T10:00:00Z');
    }
    if ($afterText !== '' && ($since === null || !preg_match('/^[1-9][0-9]{0,9}$/D', $afterText) || (int) $afterText > 2147483647)) {
        return respondError($response, 400, 'invalid_request', 'after is an account id, sent with the since it came with');
    }

    iamHousekeeping();

    return respondJson($response, anonymizedAccountsPage(iamDb(), $since, $afterText === '' ? null : (int) $afterText))
        ->withHeader('Cache-Control', 'public, max-age=60');
});
