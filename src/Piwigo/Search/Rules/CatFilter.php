<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `cat` saved-search filter — list of category ids to match
 * against image_category.category_id; the `subIncluded` flag
 * expands each id to its subtree via CategoryService::getSubcatIds.
 */
final readonly class CatFilter
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public array $categoryIds,
        public bool $subIncluded,
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
        $subInc = $raw['sub_inc'] ?? false;
        return new self($ids, (bool) $subInc);
    }
}
