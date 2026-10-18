<?php

declare(strict_types=1);

namespace Anotoki\Lib\Audit;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What the admin tools write their entries through: AuditLog, or a site's own
 * writer with the same promise - it never throws. (The modules' routes and
 * Backups take one; a site passes its AuditLog.)
 */
interface AuditRecorder
{
    /**
     * One entry: who did what to what. Never throws.
     *
     * @param array<string, mixed> $details
     * @param ?array{0: string, 1: int|string} $scope
     */
    public function record(
        ?ServerRequestInterface $request,
        int|string|null $actorId,
        string $action,
        ?string $targetType = null,
        int|string|null $targetId = null,
        array $details = [],
        ?array $scope = null,
        ?string $session = null,
    ): void;
}
