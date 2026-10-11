<?php

declare(strict_types=1);

namespace Anotoki\Lib\People;

use Anotoki\Lib\Auth\IamClient;
use Closure;
use InvalidArgumentException;
use PDO;

/**
 * A site's people, put together once: its table (PeopleTable), its connection,
 * its IAM, and the hooks the scrub calls for the copies of names kept
 * elsewhere. What the middleware, the routes and a site's command line take.
 *
 *   $people = new People(
 *       new PeopleTable(name: 'username', lastSeen: null, nameFallback: fn (int $sub): string => "user$sub"),
 *       fn (): PDO => db(),
 *       $iam,
 *       hooks: [
 *           // genshin: the feedback's snapshots of who sent it
 *           function (PDO $pdo, int $sub, ?array $former): void {
 *               $pdo->prepare("UPDATE feedback SET username = ?, email = NULL, ip = NULL, user_agent = NULL WHERE user_id = ?")
 *                   ->execute(["deleted-$sub", $sub]);
 *           },
 *       ],
 *   );
 *
 * Hooks are fn(PDO $pdo, int $sub, ?array $former): void, inside the account's
 * transaction, on the scrub's connection. They must be idempotent - an id can
 * come again - and must only UPDATE: the person's data stays, nobody's now.
 * Text the person typed (answers, notes, a feedback's words) is kept.
 */
final class People
{
    /** @var list<Closure(PDO, int, ?array<string, mixed>): void> */
    public readonly array $hooks;

    /**
     * @param Closure(): PDO $pdo  the site's connection, made when a request needs it
     * @param list<Closure(PDO, int, ?array<string, mixed>): void> $hooks
     * @param ?Closure(string $url, float $timeout): array{status: int, body: ?string} $fetch  how the IAM is asked
     */
    public function __construct(
        public readonly PeopleTable $table,
        private readonly Closure $pdo,
        public readonly IamClient $iam,
        array $hooks = [],
        private readonly ?Closure $fetch = null,
    ) {
        foreach ($hooks as $hook) {
            if (!$hook instanceof Closure) {
                throw new InvalidArgumentException('People: every hook is a Closure(PDO $pdo, int $sub, ?array $former): void.');
            }
        }
        $this->hooks = array_values($hooks);
    }

    public function pdo(): PDO
    {
        return ($this->pdo)();
    }

    public function recorder(): PeopleRecorder
    {
        return new PeopleRecorder($this->table);
    }

    /** A scrubber on the site's connection - or on $pdo, a connection of its own. */
    public function scrubber(?PDO $pdo = null): Scrubber
    {
        return Scrubber::of($pdo ?? $this->pdo(), $this->table, $this->iam, $this->hooks, $this->fetch);
    }
}
