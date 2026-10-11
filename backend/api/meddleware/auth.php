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
    // should it hold one anyway, it is not let in by it. One whose deletion
    // is pending is: its account page is where the deletion is cancelled -
    // and applicationAccessRefusal() issues nothing with it to any site.
    if ($user === null || asBool($user['disabled']) || accountAwaitsApproval($user)) {
        return null;
    }

    return ['user' => $user, 'session' => $session];
}

function requireSession(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        // The account and admin pages are ordinary traffic housekeeping rides
        // on (the host has no cron) - before the session is read, so an
        // account whose deletion has just come due is signed out by it rather
        // than served once more.
        iamHousekeeping();

        $signIn = currentSignIn($request);

        if ($signIn === null) {
            $refusal = respondError(new \Slim\Psr7\Response(), 401, 'no_session', 'Sign in first');
            // A cookie naming a session that is over is only clutter now - and
            // a sign-in hint with no session behind it would send every site
            // that reads it on a hop for nothing (api/session.php). So is one
            // under the old bare name on https, which is never read: a browser
            // that sends only that signs in once more.
            $refusal = sessionSecretFrom($request) !== null ? withoutSessionCookie($refusal) : $refusal;
            $refusal = signInHintSent($request) ? withoutSignInHint($refusal) : $refusal;

            return withoutOldSessionCookie($request, $refusal);
        }

        touchSession(iamDb(), $signIn['session']);

        $response = $handler->handle(
            $request->withAttribute('user', $signIn['user'])->withAttribute('session', $signIn['session'])
        );

        // The sign-in hint, unless the handler decided about it itself (asking
        // for the deletion clears it, cancelling it sets a new one, ending this
        // very session clears it with the cookie). An account no site lets in -
        // its deletion pending - has none: one that reached this browser
        // anyway, through a request that raced the deletion, goes here. Every
        // IAM page asks for the account first, so a live session whose browser
        // has none - opened before the hint was, or a browser that lost it -
        // gets one here: only while the session is still live after the
        // handler, which may have ended it.
        if (!signInHintInResponse($response)) {
            if (!signInHintAllowed($signIn['user'])) {
                $response = signInHintSent($request) ? withoutSignInHint($response) : $response;
            } elseif (!signInHintSent($request) && sessionLive(sessionById(iamDb(), (int) $signIn['session']['id']))) {
                $response = withSignInHint($response, $signIn['session']);
            }
        }

        // A cookie under the old bare name, sent beside the __Host- one, goes.
        return withoutOldSessionCookie($request, $response);
    };
}

/**
 * Must come after requireSession() in the chain (added before it).
 *
 * An administrator whose own deletion is pending (api/account_deletion.php)
 * administers nothing until they cancel it: the account is closed but for
 * cancelling, and iamAdminCount() no longer counts them - so the last-admin
 * rule holds through the day their account goes.
 */
function requireAdmin(): callable
{
    return function (Request $request, RequestHandler $handler): Response {
        $user = $request->getAttribute('user');

        if (!$user || !isIamAdmin((int) $user['id'])) {
            return respondError(new \Slim\Psr7\Response(), 403, 'forbidden', 'Only IAM administrators can do this');
        }

        if (accountDeletionPending($user)) {
            return respondError(new \Slim\Psr7\Response(), 403, 'account_deletion_pending', 'This account is being deleted - cancel the deletion on the account page to use the admin panel again');
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
