<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use Anotoki\Lib\Support\AtomicFile;
use Anotoki\Lib\Support\GuardedDirectory;
use RuntimeException;

/**
 * The download tickets of backups: a browser's navigation sends no Bearer
 * header, so the page first asks for a ticket (behind the site's ADMIN check
 * and the proof) and then navigates to the file with it - and the browser
 * writes the file to disk instead of holding it in memory.
 *
 * A ticket is 32 random bytes (base64url), good for one download of one backup
 * within 60 seconds (the site's ticketSeconds). Only its SHA-256 is kept (a
 * file in a denied folder), so a copy of the folder opens nothing; it is gone
 * the moment it is used - by the first request that brings it, for the backup
 * named or any other. It keeps who it was issued to (`by`): a download route
 * that knows who asks may hold the ticket to that person.
 *
 * @internal
 */
final class Tickets
{
    public function __construct(private readonly string $directory, private readonly int $seconds = 60) {}

    /** A new ticket for the backup $id (the ticket itself: the only time it is anywhere but the browser). */
    public function issue(string $id, int|string|null $actorId): string
    {
        if (!GuardedDirectory::ensure($this->directory)) {
            throw new RuntimeException('The tickets folder cannot be written.');
        }
        $this->sweep();
        $ticket = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $record = json_encode(['backup' => $id, 'expires' => time() + $this->seconds, 'by' => $actorId]);
        if (!AtomicFile::write($this->file($ticket), (string) $record, exclusive: true, mode: 0600)) {
            throw new RuntimeException('The ticket could not be written.');
        }

        return $ticket;
    }

    /**
     * Uses the ticket up: true when it was good for the backup $id - issued for
     * it, not used before, not expired, and (with $by: [the person asking])
     * issued to that person - nobody is never anybody. Whatever the answer, it
     * is good no more.
     *
     * @param ?array{0: int|string|null} $by
     */
    public function redeem(string $ticket, string $id, ?array $by = null): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $ticket) !== 1) {
            return false;
        }
        $file = $this->file($ticket);
        $record = @file_get_contents($file);
        // Gone with the first request that unlinks it: two at once, one wins.
        if (!is_string($record) || !@unlink($file)) {
            return false;
        }

        return self::goodFor(json_decode($record, true), $id, $by);
    }

    /**
     * Whether the ticket is good for the backup $id now, as redeem() judges -
     * without using it up: what a HEAD asks.
     *
     * @param ?array{0: int|string|null} $by
     */
    public function good(string $ticket, string $id, ?array $by = null): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $ticket) !== 1) {
            return false;
        }
        $record = @file_get_contents($this->file($ticket));

        return is_string($record) && self::goodFor(json_decode($record, true), $id, $by);
    }

    /** @param ?array{0: int|string|null} $by */
    private static function goodFor(mixed $data, string $id, ?array $by): bool
    {
        if ($by !== null) {
            $asking = $by[0] ?? null;
            $issuedTo = is_array($data) ? $data['by'] ?? null : null;
            if (!is_scalar($asking) || !is_scalar($issuedTo) || (string) $asking !== (string) $issuedTo) {
                return false;
            }
        }

        return is_array($data) && is_string($data['backup'] ?? null) && hash_equals($data['backup'], $id) && is_int($data['expires'] ?? null) && $data['expires'] >= time();
    }

    /** Tickets past their time go (they would be refused anyway). */
    private function sweep(): void
    {
        foreach (glob($this->directory . '/*.ticket') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data) || !is_int($data['expires'] ?? null) || $data['expires'] < time() - 60) {
                @unlink($file);
            }
        }
    }

    private function file(string $ticket): string
    {
        return $this->directory . '/' . hash('sha256', $ticket) . '.ticket';
    }
}
