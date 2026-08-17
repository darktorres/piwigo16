<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/sync-metadata` body DTO.
 */
final readonly class ImageSyncMetadataInput
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
        $rawIds = $raw['imageIds'] ?? null;
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (is_int($id)) {
                    $ids[] = $id;
                } elseif (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return new self(imageIds: $ids);
    }
}
