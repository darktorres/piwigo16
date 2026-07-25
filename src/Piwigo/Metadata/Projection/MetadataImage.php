<?php

declare(strict_types=1);

namespace Piwigo\Metadata\Projection;

/**
 * Typed row shape shared by
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByIds()} and
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByStorageCategoryIds()}
 * (P17-23 Stage 1b, Metadata domain) -- both select the exact same
 * `id`/`path`/`representative_ext` triple from `piwigo_images`.
 *
 * `toArray()` is the real consumer shape here, not `fromRow()`'s own typed
 * properties: both {@see \Piwigo\Metadata\MetadataService::syncMetadata()}
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
     * @param array<string, mixed> $row a `SELECT id, path, representative_ext` row from `piwigo_images`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            path: is_string($row['path'] ?? null) ? $row['path'] : '',
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
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
