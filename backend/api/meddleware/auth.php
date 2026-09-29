<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Route middleware for the IAM's own pages: a live session, and for the admin
 * console the ADMIN role in the IAM itself.
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

    if ($user === null || asBool($user['disabled'])) {
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
