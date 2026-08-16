<?php

declare(strict_types=1);

namespace Piwigo\Image\Projection;

use LogicException;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Image\ImageEntity;

/**
 * Typed row shape for `images`. `fromRow()` centralises the
 * `is_string($row['x']) ? ... :
 * default` narrowing every {@see \Piwigo\Image\ImageRepository} caller used
 * to duplicate for itself; `toArray()` hands that already-narrowed data
 * back out as a precisely-shaped array for the handful of consumers that
 * genuinely need array semantics (a growing Latte template-variable bag,
 * or a legacy `array<string, mixed>|SrcImage` signature not itself in
 * scope here) rather than a real accessor object.
 *
 * `dateAvailable`/`dateCreation`/`dateMetadataUpdate`/`lastmodified` stay
 * `string` here (this Projection's own convention) -- every real
 * consumer today (`DateHelper::formatDate()`/`timeSince()`, raw SQL
 * comparisons) already expects the raw DB DATETIME string form.
 * `ImageEntity::$lastmodified`/`$dateAvailable`/`$dateCreation` are all
 * `SqlDateTime`-typed (all 3 via the strict `sql_datetime` Type -- see
 * that entity's own docblock) -- `fromEntity()` unwraps
 * `->value`/`?->value` for all 3.
 *
 * `storageCategoryId`/`addedBy` both stay plain `?int` even though
 * `ImageEntity::$storageCategory`/`$addedByUser` are now real associations
 * (`?CategoryEntity`/`?UserEntity`) -- this Projection is a layer past the
 * repository where `0.3`'s "never touch a lazy-loaded property in
 * application code" discipline can no longer be guaranteed, so
 * `fromEntity()` unwraps `$entity->storageCategory?->id?->value`/
 * `$entity->addedByUser?->id?->value` rather than propagating the entity
 * reference (`->id` access on an uninitialized to-one proxy never triggers
 * a query, see `CategoryEntity::$representativePicture`'s own docblock for
 * why).
 */
final readonly class Image
{
    public function __construct(
        public ImageId $id,
        public string $file,
        public ?string $dateAvailable,
        public ?string $dateCreation,
        public ?string $name,
        public ?string $comment,
        public ?string $author,
        public int $hit,
        public ?int $filesize,
        public ?int $width,
        public ?int $height,
        public ?string $coi,
        public ?string $representativeExt,
        public ?string $dateMetadataUpdate,
        public ?float $ratingScore,
        public string $path,
        public ?int $storageCategoryId,
        public int $level,
        public ?Md5Sum $md5sum,
        public ?int $addedBy,
        public ?int $rotation,
        public ?float $latitude,
        public ?float $longitude,
        public string $lastmodified,
    ) {}

    public static function fromEntity(ImageEntity $entity): self
    {
        return new self(
            id: $entity->id ?? throw new LogicException('ImageEntity::$id is only null before persist; fromEntity() expects an already-persisted entity'),
            file: $entity->file,
            dateAvailable: $entity->dateAvailable?->value,
            dateCreation: $entity->dateCreation?->value,
            name: $entity->name,
            comment: $entity->comment,
            author: $entity->author,
            hit: $entity->hit,
            filesize: $entity->filesize,
            width: $entity->width,
            height: $entity->height,
            coi: $entity->coi,
            representativeExt: $entity->representativeExt,
            dateMetadataUpdate: $entity->dateMetadataUpdate,
            ratingScore: $entity->ratingScore,
            path: $entity->path,
            storageCategoryId: $entity->storageCategory?->id?->value,
            level: $entity->level,
            md5sum: $entity->md5sum,
            addedBy: $entity->addedByUser?->id?->value,
            rotation: $entity->rotation,
            latitude: $entity->latitude,
            longitude: $entity->longitude,
            lastmodified: $entity->lastmodified->value,
        );
    }

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `images`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: ImageId::from(is_numeric($row['id']) ? (int) $row['id'] : 0),
            file: is_string($row['file']) ? $row['file'] : '',
            dateAvailable: is_string($row['date_available']) ? $row['date_available'] : null,
            dateCreation: is_string($row['date_creation'] ?? null) ? $row['date_creation'] : null,
            name: is_string($row['name'] ?? null) ? $row['name'] : null,
            comment: is_string($row['comment'] ?? null) ? $row['comment'] : null,
            author: is_string($row['author'] ?? null) ? $row['author'] : null,
            hit: is_numeric($row['hit'] ?? null) ? (int) $row['hit'] : 0,
            filesize: is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
            width: is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
            height: is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
            coi: is_string($row['coi'] ?? null) ? $row['coi'] : null,
            representativeExt: is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            dateMetadataUpdate: is_string($row['date_metadata_update'] ?? null) ? $row['date_metadata_update'] : null,
            ratingScore: is_numeric($row['rating_score'] ?? null) ? (float) $row['rating_score'] : null,
            path: is_string($row['path'] ?? null) ? $row['path'] : '',
            storageCategoryId: is_numeric($row['storage_category_id'] ?? null) ? (int) $row['storage_category_id'] : null,
            level: is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            md5sum: is_string($row['md5sum'] ?? null) ? Md5Sum::from($row['md5sum']) : null,
            addedBy: is_numeric($row['added_by'] ?? null) ? (int) $row['added_by'] : null,
            rotation: is_numeric($row['rotation'] ?? null) ? (int) $row['rotation'] : null,
            latitude: is_numeric($row['latitude'] ?? null) ? (float) $row['latitude'] : null,
            longitude: is_numeric($row['longitude'] ?? null) ? (float) $row['longitude'] : null,
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
        );
    }

    /**
     * @return array{id: int, file: string, date_available: ?string, date_creation: ?string,
     *   name: ?string, comment: ?string, author: ?string, hit: int, filesize: ?int,
     *   width: ?int, height: ?int, coi: ?string, representative_ext: ?string,
     *   date_metadata_update: ?string, rating_score: ?float, path: string,
     *   storage_category_id: ?int, level: int, md5sum: ?string, added_by: ?int,
     *   rotation: ?int, latitude: ?float, longitude: ?float, lastmodified: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'file' => $this->file,
            'date_available' => $this->dateAvailable,
            'date_creation' => $this->dateCreation,
            'name' => $this->name,
            'comment' => $this->comment,
            'author' => $this->author,
            'hit' => $this->hit,
            'filesize' => $this->filesize,
            'width' => $this->width,
            'height' => $this->height,
            'coi' => $this->coi,
            'representative_ext' => $this->representativeExt,
            'date_metadata_update' => $this->dateMetadataUpdate,
            'rating_score' => $this->ratingScore,
            'path' => $this->path,
            'storage_category_id' => $this->storageCategoryId,
            'level' => $this->level,
            'md5sum' => $this->md5sum?->value,
            'added_by' => $this->addedBy,
            'rotation' => $this->rotation,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'lastmodified' => $this->lastmodified,
        ];
    }
}
