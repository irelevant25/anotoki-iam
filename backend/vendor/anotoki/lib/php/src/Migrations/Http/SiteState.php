<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations\Http;

use Anotoki\Lib\Migrations\Migrator;
use Closure;
use PDOException;

/**
 * Where a site stands, decided afresh for every request:
 *
 * - not_set_up: the site's settings files are missing or invalid;
 * - update_pending: the settings are fine and at least one migration file is
 *   ready to apply (a draft alone does not count: it never stops the site);
 * - unavailable: the settings are fine but the database cannot be reached;
 * - ready: otherwise.
 *
 * The site gives what it knows as closures: its own settings check, whether
 * its installer finished on a real host (storage/installed.lock), and how to
 * build its Migrator - called only once the settings are complete.
 */
final class SiteState
{
    public const READY = 'ready', NOT_SET_UP = 'not_set_up', UPDATE_PENDING = 'update_pending', UNAVAILABLE = 'unavailable';

    /**
     * @param Closure(): ?string  $settingsProblem  null when the settings are complete
     * @param Closure(): bool     $installed        storage/installed.lock exists
     * @param Closure(): Migrator $migrator         only called once the settings are complete
     * @param bool $blockWhilePending  false for a site that keeps answering while an update waits (the IAM)
     */
    public function __construct(
        private readonly Closure $settingsProblem,
        private readonly Closure $installed,
        private readonly Closure $migrator,
        public readonly bool $blockWhilePending = true,
    ) {
    }

    /**
     * The state now. Never remembered: one PHP process may serve many
     * requests, and an update may be applied between two of them.
     *
     * @return self::READY|self::NOT_SET_UP|self::UPDATE_PENDING|self::UNAVAILABLE
     */
    public function state(): string
    {
        if (($this->settingsProblem)() !== null) {
            return self::NOT_SET_UP;
        }

        try {
            $migrator = ($this->migrator)();

            return $migrator->applicable() === [] ? self::READY : self::UPDATE_PENDING;
        } catch (PDOException) {
            return self::UNAVAILABLE;
        }
    }

    /** Whether the site's installer finished on a real host - after which nobody is offered the setup page again. */
    public function installed(): bool
    {
        return (bool) ($this->installed)();
    }
}
