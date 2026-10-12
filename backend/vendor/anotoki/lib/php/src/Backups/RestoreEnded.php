<?php

declare(strict_types=1);

namespace Anotoki\Lib\Backups;

use RuntimeException;

/**
 * A restore whose transaction a hook inside it (the site's beforeCommit) ended - committed or rolled back -
 * so what the restore had loaded may have stayed: the restore says so instead of "nothing of it stayed".
 */
final class RestoreEnded extends RuntimeException
{
}
