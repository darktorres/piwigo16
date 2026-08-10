<?php

declare(strict_types=1);

namespace Piwigo\Metadata\Projection;

use Piwigo\Image\ImageEntity;

/**
 * Typed row shape shared by
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByIds()} and
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByStorageCategoryIds()}
 * -- both select the exact same `id`/`path`/`representative_ext` triple
 * from `images`.
 *
 * `toArray()` is the real consumer shape here, not `fromEntity()`'s own
 * typed properties: both {@see \Piwigo\Metadata\MetadataService::syncMetadata()}
 * and {@see \Piwigo\Metadata\MetadataService::getFilelist()}'s own 2 admin
 * callers (`SiteUpdateSubController`) treat this row as a growable data bag,
 * merging in filesize/exif/iptc-mapped fields before it feeds a
 * `massUpdateImages()`/`massUpdate()` batch write -- the repository still
 * centralises the initial narrowing once, but every consumer converts back
 * to array form at its own boundary rather than this shared, ever-growing
 * shape being force-fit into a fixed object contract.
 */
final readonly class MetadataImage
{
    public function __construct(
        public int $id,
        public string $path,
        public ?string $representativeExt,
    ) {}

    /**
     * `ImageEntity::$id`/`$path`/`$representativeExt` are already typed,
     * so no defensive casting is needed here the way a raw-array row
     * would require.
     */
    public static function fromEntity(ImageEntity $entity): self
    {
        return new self(
            id: $entity->id->value ?? 0,
            path: $entity->path,
            representativeExt: $entity->representativeExt,
        );
    }

    /**
     * @return array{id: int, path: string, representative_ext: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'representative_ext' => $this->representativeExt,
        ];
    }
}
