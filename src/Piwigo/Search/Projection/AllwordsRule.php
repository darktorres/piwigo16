<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['allwords']` -- free-text search across
 * `file`/`name`/`comment`/`tags`/`author`/`cat-title`/`cat-desc`.
 * Mutable, same rationale as {@see \Piwigo\Category\Projection\
 * ComputedCategoryRow}: {@see \Piwigo\Search\SearchFilterRenderer::
 * render()} narrows `$words` in place (intersects against what the
 * current user is actually allowed to see) rather than rebuilding the
 * whole rule.
 */
final class AllwordsRule
{
    /**
     * @param list<string> $words
     * @param 'AND'|'OR' $mode
     * @param list<string> $fields
     */
    public function __construct(
        public array $words = [],
        public string $mode = 'AND',
        public array $fields = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $words = $row['words'] ?? null;
        $fields = $row['fields'] ?? null;
        $mode = $row['mode'] ?? null;

        return new self(
            words: is_array($words) ? array_values(array_filter($words, is_string(...))) : [],
            mode: $mode === 'OR' ? 'OR' : 'AND',
            fields: is_array($fields) ? array_values(array_filter($fields, is_string(...))) : [],
        );
    }

    /**
     * @return array{words: list<string>, mode: string, fields: list<string>}
     */
    public function toArray(): array
    {
        return [
            'words' => $this->words,
            'mode' => $this->mode,
            'fields' => $this->fields,
        ];
    }
}
