<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Route middleware for the IAM's own pages: a live session, and for the admin
 * panel the ADMIN role in the IAM itself.
 *
 * The user is read fresh on every request rather than cached in the session,
 * so a disabled account or a role taken away counts from the next request.
 */

/**
 * The signed-in account and its session, or null - without refusing.
 * For endpoints that behave differently when somebody is signed in.
 *
 * @return array{user: array, session: array}|null
 */
function currentSignIn(Request $request): ?array
{
    $pdo = iamDb();
    $session = sessionFromRequest($pdo, $request);

    if ($session === null) {
        return null;
    }

    $user = userById($pdo, (int) $session['user_id']);

    // An account waiting for approval never gets a session (signInRefusal());
    // should it hold one anyway, it is not let in by it.
    if ($user === null || asBool($user['disabled']) || accountAwaitsApproval($user)) {
        return null;
    }

    return ['user' => $user, 'session' => $session];
}

function requireSession(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $signIn = currentSignIn($request);

        if ($signIn === null) {
            $refusal = respondError(new \Slim\Psr7\Response(), 401, 'no_session', 'Sign in first');
            // A cookie naming a session that is over is only clutter now.
            return sessionSecretFrom($request) !== null ? withoutSessionCookie($refusal) : $refusal;
        }

        touchSession(iamDb(), $signIn['session']);

        return $handler->handle(
            $request->withAttribute('user', $signIn['user'])->withAttribute('session', $signIn['session'])
        );
    };
}

/** Must come after requireSession() in the chain (added before it). */
function requireAdmin(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $user = $request->getAttribute('user');

        if (!$user || !isIamAdmin((int) $user['id'])) {
            return respondError(new \Slim\Psr7\Response(), 403, 'forbidden', 'Only IAM administrators can do this');
        }

        return $handler->handle($request);
    };
}

/**
 * For the admin panel: every /applications* route answers 503
 * sign_in_rules_unavailable while migration 010 is pending - they read and
 * write its columns (login_required, each role's may_sign_in), and would
 * otherwise be a 500. Everything else in the panel works, the Migrations page
 * that applies it first of all. The /users* routes work too: what they read of
 * 010 answers as before it while it is pending, so the owner can still make
 * an account or change somebody's roles in that window. Decided on the
 * route's own pattern, not the address, so no spelling of a path slips past.
 * Must come after requireAdmin() in the chain (added before it).
 *
 * The same answer from the approvals - the list, approving and declining -
 * while migration 012 is pending: there is nothing to approve before it, and
 * no column to read. The user pages read it only once it is there, and keep
 * working in that window.
 */
function requireSignInRules(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $route = \Slim\Routing\RouteContext::fromRequest($request)->getRoute();
        $pattern = $route !== null ? $route->getPattern() : '';

        $unready = (preg_match('#^/api/admin/applications(/|$)#', $pattern) && !signInRulesReady(iamDb()))
            || (preg_match('#^/api/admin/(approvals|users/[^/]+/(approve|decline))$#', $pattern) && !accountApprovalReady(iamDb()));

        if ($unready) {
            $refusal = signInRulesUnavailable();
            return respondError(new \Slim\Psr7\Response(), $refusal['status'], $refusal['code'], $refusal['error']);
        }

        return $handler->handle($request);
    };
}
