<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Categories;

/**
 * `DELETE /api/v1/categories/{id}` body DTO -- mirrors
 * `Ws\Categories\DeleteParams`'s own `photo_deletion_mode` field. A
 * request body on DELETE is unusual but deliberate here: it's the only
 * way to carry this real, meaningful option (the WS method itself
 * accepts it as a regular POST param for the same reason).
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
