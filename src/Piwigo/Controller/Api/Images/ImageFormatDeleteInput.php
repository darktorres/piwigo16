<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/formats/actions/delete` body DTO.
 */
final readonly class ImageFormatDeleteInput
{
    /**
     * @param list<int> $formatIds
     */
    public function __construct(
        public array $formatIds,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            formatIds: self::intList($raw['formatIds'] ?? null),
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

        return array_values(array_unique($ids));
    }
}
