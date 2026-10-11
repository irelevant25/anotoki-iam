<?php

declare(strict_types=1);

namespace Anotoki\Lib\Auth;

/**
 * A person, as a verified access token says: the only way a site ever knows
 * who somebody is. Never built from a body, a header of the site's own or a
 * cookie - AccessTokens::verify() makes it, and the tests' TestIam signs the
 * tokens it is made from.
 *
 * - sub: the IAM user id, a positive integer - every site's `users.id`
 * - name: preferred_username, trimmed; null when the token carries none
 * - roles: this application's roles, upper case, each once. MEMBER - which
 *   every account holds - is an ordinary person, never a reason to refuse
 *   anybody: ADMIN and EDITOR open a site's admin area
 * - sid, authTime: the IAM session the token was minted under and when its
 *   person signed in there (null for a personal token, which has no session)
 * - preferences: the account's theme, dates and clock as the claim has them
 *   (null from an IAM before its migration 015, or without the profile scope)
 */
final class Person
{
    /**
     * @param list<string> $roles
     * @param ?array<string, mixed> $preferences
     * @param array<string, mixed> $claims  every verified claim, as the token has it
     */
    public function __construct(
        public readonly int $sub,
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $locale,
        public readonly ?array $preferences,
        public readonly array $roles,
        public readonly ?string $sid,
        public readonly ?int $authTime,
        public readonly string $scope,
        public readonly array $claims,
    ) {
    }

    /**
     * The person of verified claims, or null when they name nobody usable (the
     * sub is not an IAM user id). AccessTokens calls it once the signature,
     * issuer, audience and times are checked; nothing else should.
     *
     * @param array<string, mixed> $claims
     */
    public static function fromClaims(array $claims): ?self
    {
        $sub = self::userId($claims['sub'] ?? null);
        if ($sub === null) {
            return null;
        }

        return new self(
            sub: $sub,
            name: self::text($claims['preferred_username'] ?? null),
            email: self::text($claims['email'] ?? null),
            emailVerified: ($claims['email_verified'] ?? false) === true,
            locale: self::text($claims['locale'] ?? null),
            preferences: is_array($claims['preferences'] ?? null) && !array_is_list($claims['preferences']) ? $claims['preferences'] : null,
            roles: self::roles($claims['roles'] ?? null),
            sid: self::text($claims['sid'] ?? null),
            authTime: is_int($claims['auth_time'] ?? null) ? $claims['auth_time'] : null,
            scope: is_string($claims['scope'] ?? null) ? $claims['scope'] : '',
            claims: $claims,
        );
    }

    /**
     * An IAM user id: a positive integer of at most 18 digits, written as the
     * tokens write it (a decimal string, no sign, no leading zero) - or null.
     */
    public static function userId(mixed $sub): ?int
    {
        return is_string($sub) && preg_match('/^[1-9][0-9]{0,17}$/D', $sub) === 1 ? (int) $sub : null;
    }

    /**
     * This application's roles - upper case, each once, never anything but a
     * non-empty word.
     *
     * @return list<string>
     */
    public static function roles(mixed $roles): array
    {
        $clean = [];
        foreach (is_array($roles) ? $roles : [] as $role) {
            if (is_string($role) && trim($role) !== '') {
                $clean[strtoupper(trim($role))] = true;
            }
        }

        return array_keys($clean);
    }

    /** Whether the person holds one of these roles (any case). */
    public function has(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if (in_array(strtoupper($role), $this->roles, true)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->has('ADMIN');
    }

    /** ADMIN or EDITOR: whoever may use the site's admin area. */
    public function isStaff(): bool
    {
        return $this->has('ADMIN', 'EDITOR');
    }

    /** Whether the person signed in at the IAM within the last $seconds (a backup restore asks for a recent sign-in). */
    public function signedInWithin(int $seconds, ?int $now = null): bool
    {
        return $this->authTime !== null && ($now ?? time()) - $this->authTime <= $seconds;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
