<?php

declare(strict_types=1);

namespace Anotoki\Lib\Migrations;

/**
 * One folder of migration files, `NNN_name.sql`, applied in natural order.
 *
 * Most sites have one set; the genshin site has two (`users`, then
 * `genshin_impact`), and its bookkeeping table records each file with the
 * set's name in its `folder` column. For a one-set site the name is only a
 * label (`iam`, `survey`, ...), shown on the Migrations page and in the
 * answers of the admin routes.
 */
final class MigrationSet
{
    public function __construct(
        public readonly string $name,
        public readonly string $directory,
    ) {
    }
}
