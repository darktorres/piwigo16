<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Category\CategoryEntity;
use Piwigo\Category\CategoryStatus;

/**
 * Typed row shape for `piwigo_categories` (P17-23 Stage 1b, Category domain
 * -- `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing every {@see \Piwigo\Category\CategoryRepository}
 * caller used to duplicate for itself; `toArray()` hands that
 * already-narrowed data back out as a precisely-shaped array for consumers
 * that genuinely need array semantics (a growing Smarty template-variable
 * bag, or an `array_merge()`-based row-building step) rather than a real
 * accessor object.
 *
 * `commentable`/`visible` are real `bool` here, not the raw tinyint the
 * column now stores -- migration `Version20260723222502` already made them
 * a genuine boolean column; a repository-layer projection is the correct
 * place to finish that conversion to a real PHP `bool` once, rather than
 * leaving every consumer to re-cast `(bool) $row['commentable']` itself.
 *
 * `lastmodified` stays `string`, not `\DateTimeImmutable` -- every real
 * consumer today (`DateHelper::formatDate()`/`timeSince()`, raw SQL
 * comparisons) already expects the raw DB DATETIME string form, same
 * reasoning as {@see \Piwigo\Image\Projection\Image}.
 */
final readonly class Category
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $idUppercat,
        public ?string $comment,
        public ?string $dir,
        public ?int $rank,
        public string $status,
        public ?int $siteId,
        public bool $visible,
        public ?int $representativePictureId,
        public string $uppercats,
        public bool $commentable,
        public ?string $globalRank,
        public ?string $imageOrder,
        public ?string $permalink,
        public string $lastmodified,
    ) {}

    public static function fromEntity(CategoryEntity $entity): self
    {
        return new self(
            id: $entity->id ?? 0,
            name: $entity->name,
            idUppercat: $entity->idUppercat,
            comment: $entity->comment,
            dir: $entity->dir,
            rank: $entity->rank,
            status: $entity->status->value,
            siteId: $entity->siteId,
            visible: $entity->visible,
            representativePictureId: $entity->representativePictureId,
            uppercats: $entity->uppercats,
            commentable: $entity->commentable,
            globalRank: $entity->globalRank,
            imageOrder: $entity->imageOrder,
            permalink: $entity->permalink,
            lastmodified: $entity->lastmodified,
        );
    }

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_categories`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_numeric($row['id']) ? (int) $row['id'] : 0,
            name: is_string($row['name'] ?? null) ? $row['name'] : '',
            idUppercat: is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
            comment: is_string($row['comment'] ?? null) ? $row['comment'] : null,
            dir: is_string($row['dir'] ?? null) ? $row['dir'] : null,
            rank: is_numeric($row['rank'] ?? null) ? (int) $row['rank'] : null,
            status: self::narrowStatus($row['status'] ?? null),
            siteId: is_numeric($row['site_id'] ?? null) ? (int) $row['site_id'] : null,
            visible: is_numeric($row['visible'] ?? null) ? (bool) (int) $row['visible'] : true,
            representativePictureId: is_numeric($row['representative_picture_id'] ?? null) ? (int) $row['representative_picture_id'] : null,
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            commentable: is_numeric($row['commentable'] ?? null) ? (bool) (int) $row['commentable'] : true,
            globalRank: is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            imageOrder: is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
            permalink: is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
        );
    }

    /**
     * `$row['status']` is a plain string for a raw DBAL row, but a real
     * `CategoryStatus` instance when `$row` came from DQL array hydration
     * against {@see CategoryEntity}'s `enumType`-mapped `$status` (Phase 5
     * Item 21's own gotcha, applied here: `enumType` hydration applies to
     * scalar/array DQL selects too, not just full-entity reads) --
     * `fromRow()` accepts either shape rather than assuming one caller
     * convention.
     */
    private static function narrowStatus(mixed $value): string
    {
        if ($value instanceof CategoryStatus) {
            return $value->value;
        }

        return is_string($value) ? $value : 'public';
    }

    /**
     * @return array{id: int, name: string, id_uppercat: ?int, comment: ?string,
     *   dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool,
     *   representative_picture_id: ?int, uppercats: string, commentable: bool,
     *   global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'id_uppercat' => $this->idUppercat,
            'comment' => $this->comment,
            'dir' => $this->dir,
            'rank' => $this->rank,
            'status' => $this->status,
            'site_id' => $this->siteId,
            'visible' => $this->visible,
            'representative_picture_id' => $this->representativePictureId,
            'uppercats' => $this->uppercats,
            'commentable' => $this->commentable,
            'global_rank' => $this->globalRank,
            'image_order' => $this->imageOrder,
            'permalink' => $this->permalink,
            'lastmodified' => $this->lastmodified,
        ];
    }
}
