<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

/**
 * `POST /api/v1/session/caddie` body DTO -- mirrors
 * `Ws\Core\CaddieAddParams`'s own `image_id` shape.
 */
final readonly class CaddieAddInput
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            imageIds: self::intList($raw['imageIds'] ?? null),
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
