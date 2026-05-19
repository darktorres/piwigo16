<?php

declare(strict_types=1);

namespace Piwigo\Image\Entity;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\Md5Sum;
use Piwigo\Common\ValueObject\MysqlDate;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\RelPath;
use Piwigo\Common\ValueObject\UserId;

/**
 * Typed entity wrapping one row of the `images` table.
 *
 * Constructed via `Image::fromRow($row)` — the one place that touches
 * the raw `array<string, mixed>` returned by DBAL. Every consumer
 * downstream reads typed properties.
 *
 * Mutation produces a new Image: there's no `withRotation(int)` setter
 * yet because no current consumer mutates an Image and reads it back —
 * they all read or persist via the repository. When the first such
 * caller arrives, add a `with*` helper that returns a new instance.
 *
 * @see fromRow() — the row → entity bridge
 */
final readonly class Image
{
    public function __construct(
        public ImageId         $id,
        public RelPath         $file,
        public ?MysqlDateTime  $dateAvailable,
        public ?MysqlDateTime  $dateCreation,
        public ?string         $name,
        public ?string         $comment,
        public ?string         $author,
        public int             $hit,
        public ?int            $filesize,
        public ?int            $width,
        public ?int            $height,
        public ?string         $coi,
        public ?string         $representativeExt,
        public ?MysqlDate      $dateMetadataUpdate,
        public ?float          $ratingScore,
        public RelPath         $path,
        public ?CategoryId     $storageCategoryId,
        public int             $level,
        public ?Md5Sum         $md5sum,
        public ?UserId         $addedBy,
        public ?int            $rotation,
        public ?float          $latitude,
        public ?float          $longitude,
        public MysqlDateTime   $lastModified,
    ) {
    }

    /**
     * Hydrate from a raw row map (the result of `fetchAssociative` on
     * `images.*`). This is the canonical row → typed entity bridge —
     * the only place in the codebase that needs to narrow mixed values
     * out of an images-table row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        $id    = is_numeric($idRaw) ? (int) $idRaw : null;
        if ($id === null) {
            throw new \InvalidArgumentException('Image row is missing required `id` field');
        }

        return new self(
            id:                  ImageId::from($id),
            file:                RelPath::from(self::str($row, 'file', '')),
            dateAvailable:       MysqlDateTime::tryFrom($row['date_available'] ?? null),
            dateCreation:        MysqlDateTime::tryFrom($row['date_creation'] ?? null),
            name:                self::nullableStr($row, 'name'),
            comment:             self::nullableStr($row, 'comment'),
            author:              self::nullableStr($row, 'author'),
            hit:                 self::int($row, 'hit', 0),
            filesize:            self::nullableInt($row, 'filesize'),
            width:               self::nullableInt($row, 'width'),
            height:              self::nullableInt($row, 'height'),
            coi:                 self::nullableStr($row, 'coi'),
            representativeExt:   self::nullableStr($row, 'representative_ext'),
            dateMetadataUpdate:  MysqlDate::tryFrom($row['date_metadata_update'] ?? null),
            ratingScore:         self::nullableFloat($row, 'rating_score'),
            path:                RelPath::from(self::str($row, 'path', '')),
            storageCategoryId:   CategoryId::tryFrom($row['storage_category_id'] ?? null),
            level:               self::int($row, 'level', 0),
            md5sum:              Md5Sum::tryFrom($row['md5sum'] ?? null),
            addedBy:             UserId::tryFrom($row['added_by'] ?? null),
            rotation:            self::nullableInt($row, 'rotation'),
            latitude:            self::nullableFloat($row, 'latitude'),
            longitude:           self::nullableFloat($row, 'longitude'),
            lastModified:        self::requiredDateTime($row, 'lastmodified'),
        );
    }

    /** Aspect ratio (width / height), or null when either dimension is missing. */
    public function aspectRatio(): ?float
    {
        if ($this->width === null || $this->height === null || $this->height === 0) {
            return null;
        }
        return $this->width / $this->height;
    }

    public function isPortrait(): bool
    {
        $ratio = $this->aspectRatio();
        return $ratio !== null && $ratio < 1.0;
    }

    public function isLandscape(): bool
    {
        $ratio = $this->aspectRatio();
        return $ratio !== null && $ratio > 1.0;
    }

    // -- internal helpers --------------------------------------------------

    /** @param array<string, mixed> $row */
    private static function str(array $row, string $key, string $default): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    /** @param array<string, mixed> $row */
    private static function nullableStr(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function int(array $row, string $key, int $default): int
    {
        $v = $row[$key] ?? null;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $v = $row[$key] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableFloat(array $row, string $key): ?float
    {
        $v = $row[$key] ?? null;
        return is_numeric($v) ? (float) $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function requiredDateTime(array $row, string $key): MysqlDateTime
    {
        $v  = $row[$key] ?? null;
        $dt = is_string($v) ? MysqlDateTime::tryFrom($v) : null;
        if ($dt === null) {
            throw new \InvalidArgumentException(
                "Image row is missing required datetime field `{$key}`",
            );
        }
        return $dt;
    }
}
