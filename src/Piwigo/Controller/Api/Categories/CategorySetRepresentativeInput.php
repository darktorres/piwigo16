<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `PUT /api/v1/categories/{id}/representative` body DTO.
 */
final readonly class CategorySetRepresentativeInput
{
    public function __construct(
        public int $imageId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $imageId = $raw['imageId'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
        );
    }
}
