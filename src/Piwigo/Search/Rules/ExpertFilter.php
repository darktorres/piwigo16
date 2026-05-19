<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `expert` saved-search filter — a single qsearch query string that
 * gets fed back through SearchService::getQuickSearchResults to
 * fold quick-search semantics into the regular-search results.
 */
final readonly class ExpertFilter
{
    public function __construct(public string $query)
    {
    }

    /** @param array<int|string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $string = $raw['string'] ?? null;
        if (!is_string($string) || $string === '') {
            return null;
        }
        return new self($string);
    }
}
