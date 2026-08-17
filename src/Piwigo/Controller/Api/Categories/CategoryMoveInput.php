<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `POST /api/v1/categories/actions/move` body DTO -- mirrors
 * `Ws\Categories\MoveParams`'s own `category_id`/`parent` fields.
 */
final readonly class CategoryMoveInput
{
    /**
     * @param list<int> $categoryIds
     */
    public function __construct(
        public array $categoryIds,
        public int $parentId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $parentId = $raw['parentId'] ?? null;

        return new self(
            categoryIds: self::intList($raw['categoryIds'] ?? null),
            parentId: is_int($parentId) ? $parentId : 0,
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
