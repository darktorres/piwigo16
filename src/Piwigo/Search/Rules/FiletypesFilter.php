<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `filetypes` saved-search filter — list of file extensions
 * (without dot) the image `path` must end with. Matched with
 * SQL `path LIKE '%.<ext>'`.
 */
final readonly class FiletypesFilter
{
    /** @param list<string> $extensions */
    public function __construct(public array $extensions)
    {
    }

    /** @param array<int|string, mixed> $raw  flat list of extension codes */
    public static function fromArray(array $raw): ?self
    {
        $extensions = array_values(array_filter(
            array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $raw),
            static fn (string $e): bool => $e !== '',
        ));
        return $extensions === [] ? null : new self($extensions);
    }
}
