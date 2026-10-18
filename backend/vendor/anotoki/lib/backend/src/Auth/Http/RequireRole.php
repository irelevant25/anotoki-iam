<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth\Http;

use Anotoki\Lib\Support\Refusal;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Somebody signed in with one of these roles - read from the token, never from
 * the database, so the admin check works on a database a pending migration has
 * yet to build (the Migrations routes need exactly that). Nobody and a visitor
 * answer 401 sign_in_required; a person without the role 403 `forbidden` - or
 * the site's own code and sentence (refusing()): Japanese Academy's and the
 * build analyzer's admin_only and editor_only.
 *
 *   $app->group('/api/admin', $routes)->add(RequireRole::any('ADMIN', 'EDITOR'));
 *   MigrationsRoutes::admin($app, '/api/admin/migrations', $migrator)->add(RequireRole::admin());
 */
final class RequireRole implements MiddlewareInterface
{
    /** @var list<string> */
    public readonly array $roles;

    /** @param list<string> $roles */
    public function __construct(array $roles, public readonly string $code = 'forbidden', public readonly ?string $message = null)
    {
        $clean = [];
        foreach ($roles as $role) {
            if (!is_string($role) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $role) !== 1) {
                throw new InvalidArgumentException('RequireRole: every role is a word.');
            }
            $clean[] = strtoupper($role);
        }
        if ($clean === []) {
            throw new InvalidArgumentException('RequireRole: name at least one role - RequirePerson is for "anybody signed in".');
        }
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('RequireRole: the code is a word in snake_case.');
        }
        $this->roles = array_values(array_unique($clean));
    }

    /** One of these roles. */
    public static function any(string ...$roles): self
    {
        return new self(array_values($roles));
    }

    /** The site's ADMIN. */
    public static function admin(): self
    {
        return new self(['ADMIN']);
    }

    /** The same check, refusing with the site's own code (and sentence). */
    public function refusing(string $code, ?string $message = null): self
    {
        return new self($this->roles, $code, $message);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identify = Identify::of($request);
        $person = Identify::person($request);
        if ($person === null) {
            return $identify->signInRequiredRefusal();
        }
        if (!$person->has(...$this->roles)) {
            return $identify->refuse(new Refusal(403, $this->code, $this->message ?? $this->sentence()));
        }

        return $handler->handle($request);
    }

    private function sentence(): string
    {
        return $this->roles === ['ADMIN']
            ? 'Only an administrator of this site may do this.'
            : 'This needs one of these roles here: ' . implode(', ', $this->roles) . '. Ask the site\'s administrator.';
    }
}
