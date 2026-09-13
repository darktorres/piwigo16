<?php

declare(strict_types=1);

namespace Piwigo\Metadata\Projection;

use Piwigo\Common\ValueObject\ImageId;

/**
 * Typed row shape shared by
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByIds()} and
 * {@see \Piwigo\Metadata\MetadataRepository::findImagesByStorageCategoryIds()}
 * -- both select the exact same `id`/`path`/`representative_ext` triple
 * from `images`.
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
     * Both real callers select `i.id`, `i.path`, `i.representativeExt` via
     * `Doctrine\ORM\Query::getArrayResult()`, not a full-entity
     * `getResult()` (see both call sites' own docblocks for why) -- `id`
     * still arrives as a real {@see ImageId} value object even under array
     * hydration (DBAL's custom Type conversion applies regardless of
     * hydration mode), same narrowing
     * `Image\ImageRepository::findIdsAndPathsByStorageCategoryIds()`
     * already established for its own equivalent row. Returns null for a
     * row that doesn't match this shape (never expected from either real
     * caller's own fixed `SELECT`, but this stays a narrowing boundary
     * rather than assuming the query never changes underneath it).
     *
     * @param array<array-key, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $id = $row['id'] ?? null;
        $path = $row['path'] ?? null;
        $representativeExt = $row['representativeExt'] ?? null;

        if (! $id instanceof ImageId || ! is_string($path)) {
            return null;
        }
        if ($representativeExt !== null && ! is_string($representativeExt)) {
            return null;
        }

        return new self(
            id: $id->value,
            path: $path,
            representativeExt: $representativeExt,
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
