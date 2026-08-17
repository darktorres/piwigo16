<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/delete-orphans` body DTO -- mirrors
 * `Ws\Images\DeleteOrphansParams`'s own `block_size` field (default
 * 1000).
 */
final readonly class ImageDeleteOrphansInput
{
    public function __construct(
        public int $blockSize,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $blockSize = $raw['blockSize'] ?? null;

        return new self(
            blockSize: is_int($blockSize) && $blockSize > 0 ? $blockSize : 1000,
        );
    }
}
