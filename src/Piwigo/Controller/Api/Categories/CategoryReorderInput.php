<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `POST /api/v1/categories/actions/reorder` body DTO -- a `categoryIds`
 * list with more than one entry means "here is the full, already-ordered
 * sibling list" (`rank` becomes meaningless); exactly one id plus `rank`
 * means "insert this one album at this position among its siblings".
 */
final readonly class CategoryReorderInput
{
    /**
     * @param list<int> $categoryIds
     */
    public function __construct(
        public array $categoryIds,
        public ?int $rank,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $rank = $raw['rank'] ?? null;

        return new self(
            categoryIds: self::intList($raw['categoryIds'] ?? null),
            rank: is_int($rank) ? $rank : null,
        );
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            } elseif (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        }

        return $ids;
    }
}
