<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/set-privacy-level` body DTO.
 */
final readonly class ImageSetPrivacyLevelInput
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public int $level,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $level = $raw['level'] ?? null;

        return new self(
            imageIds: self::intList($raw['imageIds'] ?? null),
            level: is_int($level) ? $level : 0,
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
