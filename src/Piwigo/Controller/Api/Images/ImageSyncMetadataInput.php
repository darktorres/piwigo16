<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/actions/sync-metadata` body DTO -- mirrors
 * `Ws\Images\SyncMetadataParams`'s own `image_id` field, already
 * typed (Symfony Console/JSON both give real ints, unlike WS's own
 * wire-format string-validation loop, which this drops as unnecessary).
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
