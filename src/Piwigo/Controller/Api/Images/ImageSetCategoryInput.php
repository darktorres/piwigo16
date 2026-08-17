<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/set-category` body DTO.
 */
final readonly class ImageSetCategoryInput
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $categoryId,
        public string $action,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $categoryId = $raw['categoryId'] ?? null;
        $action = $raw['action'] ?? null;

        return new self(
            imageIds: self::intList($raw['imageIds'] ?? null),
            categoryId: is_int($categoryId) ? $categoryId : 0,
            action: is_string($action) ? $action : 'associate',
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
