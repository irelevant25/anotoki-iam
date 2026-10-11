<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations;

use InvalidArgumentException;

/**
 * One folder of migration files, `NNN_name.sql`, applied in natural order.
 *
 * Most sites have one set of their own; the genshin site has two (`users`,
 * then `genshin_impact`), and its bookkeeping table records each file with the
 * set's name in its `folder` column. For a one-set site the name is only a
 * label (`iam`, `survey`, ...), shown on the Migrations page and in the
 * answers of the admin routes.
 *
 * A library set (MigrationSet::library()) is one the library ships - the
 * translations module's tables, say - which a site adds to its own sets. Its
 * name begins with `anotoki_`, which no site's set may take, so that its files
 * can be told from the site's in a bookkeeping table without a `folder`
 * column: there they are recorded as `<set>/<file>`, wherever the set stands
 * in the list (see Migrator).
 */
final class MigrationSet
{
    /** The beginning of every library set's name, and of no site set's. */
    public const LIBRARY_PREFIX = 'anotoki_';

    /**
     * @param bool $library  a set the library ships - MigrationSet::library() says it more plainly
     */
    public function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly bool $library = false,
    ) {
        // At most 64 characters: genshin's `folder` column is a VARCHAR(64).
        if ($library && preg_match('/^anotoki_[a-z0-9_]{1,56}$/D', $name) !== 1) {
            throw new InvalidArgumentException(
                "A library migration set is named anotoki_ and small letters, digits or _, at most 64 characters: \"$name\" is not."
            );
        }
        if (!$library && str_starts_with($name, self::LIBRARY_PREFIX)) {
            throw new InvalidArgumentException(
                "The names that begin with anotoki_ are kept for the library's migration sets: give the set \"$name\" another name."
            );
        }
    }

    /** A set the library ships, named anotoki_<something>. */
    public static function library(string $name, string $directory): self
    {
        return new self($name, $directory, true);
    }
}
