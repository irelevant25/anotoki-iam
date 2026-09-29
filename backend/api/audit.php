<?php

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The record of what was done: every admin change, and the security-relevant
 * changes an account makes to itself.
 *
 * Never throws. A change that was made must not be undone by the log failing
 * to write; the failure goes to the PHP error log instead.
 */
function audit(?Request $request, ?int $actorId, string $action, ?string $targetType = null, int|string|null $targetId = null, array $details = []): void
{
    try {
        iamDb()->prepare(
            'INSERT INTO audit_log (actor_id, action, target_type, target_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $actorId,
            $action,
            $targetType,
            $targetId === null ? null : (string) $targetId,
            $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $request !== null ? requestIp($request) : null,
        ]);
    } catch (\Throwable $e) {
        error_log('[audit] could not record ' . $action . ': ' . $e->getMessage());
    }
}
