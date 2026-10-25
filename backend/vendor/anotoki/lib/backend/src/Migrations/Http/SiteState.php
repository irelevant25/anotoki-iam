<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations\Http;

use Anotoki\Lib\Migrations\Migrator;
use Anotoki\Lib\SiteSettings\SiteSettings;
use Closure;
use PDOException;
use Throwable;

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
 *
 * A ready site its administrators closed for maintenance (0.11.0: the site
 * settings' "Getting in", given as `settings`) is still ready here - its
 * database is fine, and what runs while it is ready (the people's scrub, the
 * day's backup) runs on - but maintenance() says so, the status path says
 * `maintenance` (status()), and the SiteGate answers everybody but the site's
 * ADMIN 503 `maintenance`.
 */
final class SiteState
{
    public const READY = 'ready',
        NOT_SET_UP = 'not_set_up',
        UPDATE_PENDING = 'update_pending',
        UNAVAILABLE = 'unavailable';

    /** What the status path says of a ready site that is closed for maintenance - never what state() answers. */
    public const MAINTENANCE = 'maintenance';

    /**
     * @param Closure(): ?string  $settingsProblem  null when the settings are complete
     * @param Closure(): bool     $installed        storage/installed.lock exists
     * @param Closure(): Migrator $migrator         only called once the settings are complete
     * @param bool $blockWhilePending  false for a site that keeps answering while an update waits (the IAM)
     * @param ?SiteSettings $settings  the site settings, where the site offers "Getting in" (maintenance mode)
     */
    public function __construct(
        private readonly Closure $settingsProblem,
        private readonly Closure $installed,
        private readonly Closure $migrator,
        public readonly bool $blockWhilePending = true,
        private readonly ?SiteSettings $settings = null,
    ) {}

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

    /** The site settings this state reads maintenance from, or null. */
    public function settings(): ?SiteSettings
    {
        return $this->settings;
    }

    /** Whether the site can be closed for maintenance: it gave site settings that offer "Getting in". */
    public function offersMaintenance(): bool
    {
        return $this->settings !== null && $this->settings->offers(SiteSettings::GETTING_IN);
    }

    /**
     * Null while the site is open; while its administrators have closed it for
     * maintenance, the message they wrote, per language (empty when they wrote
     * none). Ask it of a ready site: one that is not is closed already. Never
     * throws - a switch that cannot be read is no closed site (the settings'
     * rule: a table nobody can read never takes the site down).
     *
     * @return ?array<string, string>
     */
    public function maintenance(): ?array
    {
        if (!$this->offersMaintenance()) {
            return null;
        }
        try {
            return $this->settings?->maintenance();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * What the status path says: the state - `maintenance` in place of `ready`
     * while the site is closed for maintenance, with its message per language -
     * and whether the site was installed.
     *
     * @return array{state: string, installed: bool, maintenance_message?: object}
     */
    public function status(): array
    {
        $state = $this->state();
        $message = $state === self::READY ? $this->maintenance() : null;
        if ($message !== null) {
            return ['state' => self::MAINTENANCE, 'installed' => $this->installed(), 'maintenance_message' => (object) $message];
        }

        return ['state' => $state, 'installed' => $this->installed()];
    }
}
