<?php

declare(strict_types=1);

namespace Anotoki\Lib\Translations;

use Anotoki\Lib\Support\Refusal;
use PDO;

/**
 * What a site's own tables make of its languages: who reads which one, what
 * stops one from being deleted, and what must happen before one is. The IAM
 * counts the accounts of each language; the survey refuses to delete a
 * language a survey is written in; Japanese Academy writes a copy of a
 * language's strings before it goes.
 */
interface LanguageUsage
{
    /**
     * Fields of the site's own for each language, merged into what the admin
     * pages read of it (after the library's own fields, which they never
     * replace): the IAM's ['en' => ['accounts' => 12], ...].
     *
     * @param list<string> $codes
     * @return array<string, array<string, int|string>> by code
     */
    public function extras(PDO $pdo, array $codes): array;

    /**
     * Why the language cannot be deleted, or null. Asked inside the delete's
     * transaction, with the language's row held: 409 language_in_use and how
     * many surveys use it, say.
     */
    public function refuseDelete(PDO $pdo, string $code): ?Refusal;

    /**
     * Last before the delete, inside its transaction: the site's own work (a
     * copy of the strings, say). What it answers is added to the delete's answer
     * and its audit entry (['copy' => 'file name']); throwing cancels the delete.
     *
     * @param array<string, string> $strings  the language's own strings, by key
     * @return array<string, int|string>
     */
    public function beforeDelete(PDO $pdo, string $code, array $strings): array;
}
