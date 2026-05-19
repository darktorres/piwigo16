<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `tags` saved-search filter — list of tag ids to match against
 * image_tag.tag_id with AND/OR semantics.
 */
final readonly class TagsFilter
{
    /** @param list<int> $tagIds */
    public function __construct(
        public array $tagIds,
        public AllwordsMode $mode,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $rawWords = $raw['words'] ?? null;
        if (!is_array($rawWords)) {
            return null;
        }
        $ids = [];
        foreach ($rawWords as $word) {
            if (is_numeric($word)) {
                $ids[] = (int) $word;
            }
        }
        if ($ids === []) {
            return null;
        }
        return new self($ids, AllwordsMode::tryParse($raw['mode'] ?? null));
    }
}
