<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['author']`. The original raw-array shape also
 * carried a `mode` key (always `'OR'` from every real producer) --
 * dropped here, confirmed dead: none of the 3 real consumers
 * ({@see \Piwigo\Search\SearchService::getRegularSearchResults()},
 * {@see \Piwigo\Search\SearchFilterRenderer::render()},
 * {@see \Piwigo\Controller\Api\History\HistorySearchController}) ever
 * reads it. Mutable, same rationale as {@see AllwordsRule}.
 */
final class AuthorRule
{
    /**
     * @param list<string> $words
     */
    public function __construct(
        public array $words = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $words = $row['words'] ?? null;

        return new self(
            words: is_array($words) ? array_values(array_filter($words, is_string(...))) : [],
        );
    }

    /**
     * @return array{words: list<string>}
     */
    public function toArray(): array
    {
        return [
            'words' => $this->words,
        ];
    }
}
