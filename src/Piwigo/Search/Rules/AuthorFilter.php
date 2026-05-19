<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `author` saved-search filter — list of exact author names to
 * match against image.author. Empty list = filter inactive.
 */
final readonly class AuthorFilter
{
    /** @param list<string> $words */
    public function __construct(public array $words)
    {
    }

    /** @param array<int|string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $rawWords = $raw['words'] ?? null;
        if (!is_array($rawWords)) {
            return null;
        }
        $words = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $rawWords),
            static fn (string $w): bool => $w !== '',
        ));
        return $words === [] ? null : new self($words);
    }
}
