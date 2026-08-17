<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `DELETE /api/v1/categories/{id}` body DTO -- carries `photo_deletion_mode`.
 * A request body on DELETE is unusual but deliberate here: it's the only
 * way to carry this real, meaningful option.
 */
final readonly class CategoryDeleteInput
{
    public function __construct(
        public string $photoDeletionMode,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $photoDeletionMode = $raw['photoDeletionMode'] ?? null;

        return new self(
            photoDeletionMode: is_string($photoDeletionMode) ? $photoDeletionMode : 'delete_orphans',
        );
    }
}
