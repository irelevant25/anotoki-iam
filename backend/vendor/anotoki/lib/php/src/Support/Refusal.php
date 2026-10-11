<?php

declare(strict_types=1);

namespace Anotoki\Lib\Support;

/**
 * A request refused: the HTTP status, a code a page can act on, the English
 * sentence an administrator reads, and what it is about (`key`, `language`,
 * `placeholder`, `keys`, `field`, ...), which goes into the answer beside them.
 *
 * The library's functions answer with one instead of throwing, and its routes
 * turn it into the site's error body.
 */
final class Refusal
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $message,
        public readonly array $extra = [],
    ) {
    }

    /** @param array<string, mixed> $extra */
    public static function invalid(string $code, string $message, array $extra = []): self
    {
        return new self(422, $code, $message, $extra);
    }

    /**
     * The body the library answers with when the site gives no error body of its own:
     * {code, message, ...extra}, as the migrations routes answer.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return ['code' => $this->code, 'message' => $this->message] + $this->extra;
    }
}
