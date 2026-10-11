<?php

declare(strict_types=1);

namespace Anotoki\Lib\Audit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is acting in the request being answered, held for it - so code deep
 * inside the work (genshin's DbQuery) records without being handed the
 * request: AuditLog::recordHere(). The scope is what the entries belong to
 * meanwhile (AuditLog::withScope()).
 */
final class AuditContext
{
    /** @var list<array{string, string}> scopes, innermost last */
    public array $scopes = [];

    public function __construct(
        public readonly ?ServerRequestInterface $request,
        public readonly int|string|null $actorId,
        public readonly ?string $session = null,
    ) {
    }
}
