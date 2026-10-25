<?php

declare(strict_types=1);

namespace Anotoki\Lib\RichHtml;

/**
 * An element of a text's tree: its tag (lower case), its attributes in their
 * order ([name, value], names in lower case), and what it holds - runs of words
 * (strings) and elements.
 */
final class RichElement
{
    /**
     * @param list<array{0: string, 1: string}> $attrs
     * @param list<string|RichElement> $children
     */
    public function __construct(public readonly string $tag, public readonly array $attrs, public array $children = []) {}

    /** An attribute's value as written - null for one it has not. */
    public function attribute(string $name): ?string
    {
        foreach ($this->attrs as [$each, $value]) {
            if ($each === $name) {
                return $value;
            }
        }

        return null;
    }
}
