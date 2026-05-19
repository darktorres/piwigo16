<?php

declare(strict_types=1);

namespace Piwigo\Category\Entity;

use Piwigo\Common\Enum\Privacy;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\MysqlDateTime;
use Piwigo\Common\ValueObject\Permalink;

/**
 * Typed entity wrapping one row of the `categories` table. Created by
 * {@see fromRow()} — the canonical row → entity bridge.
 *
 * Mirrors the `Image` entity convention from F5-d/1: readonly,
 * VO-typed identifiers, nullable optional columns.
 */
final readonly class Category
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?CategoryId $idUppercat,
        public ?string $comment,
        public ?string $dir,
        public ?int $rank,
        public Privacy $status,
        public ?int $siteId,
        public bool $visible,
        public ?int $representativePictureId,
        public string $uppercats,
        public bool $commentable,
        public ?string $globalRank,
        public ?string $imageOrder,
        public ?Permalink $permalink,
        public MysqlDateTime $lastModified,
    ) {
    }

    /**
     * Hydrate from a raw row map (`fetchAssociative` on `categories.*`).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('Category row is missing required `id` field');
        }
        $status = is_string($row['status'] ?? null)
            ? Privacy::from($row['status'])
            : Privacy::Public;
        $name = is_string($row['name'] ?? null) ? $row['name'] : '';
        $uppercats = is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '';
        $lastmodifiedRaw = $row['lastmodified'] ?? null;
        $lastmodified    = is_string($lastmodifiedRaw) ? MysqlDateTime::tryFrom($lastmodifiedRaw) : null;
        if ($lastmodified === null) {
            throw new \InvalidArgumentException('Category row is missing required `lastmodified` field');
        }

        return new self(
            id:                      CategoryId::from((int) $idRaw),
            name:                    $name,
            idUppercat:              CategoryId::tryFrom($row['id_uppercat'] ?? null),
            comment:                 self::nullableStr($row, 'comment'),
            dir:                     self::nullableStr($row, 'dir'),
            rank:                    self::nullableInt($row, 'rank'),
            status:                  $status,
            siteId:                  self::nullableInt($row, 'site_id'),
            visible:                 self::bool($row, 'visible', true),
            representativePictureId: self::nullableInt($row, 'representative_picture_id'),
            uppercats:               $uppercats,
            commentable:             self::bool($row, 'commentable', true),
            globalRank:              self::nullableStr($row, 'global_rank'),
            imageOrder:              self::nullableStr($row, 'image_order'),
            permalink:               Permalink::tryFrom($row['permalink'] ?? null),
            lastModified:            $lastmodified,
        );
    }

    /**
     * Inverse of {@see fromRow()} — emits the canonical `categories`-table
     * row shape for service wrappers and WS builders that still consume
     * `array<string, mixed>` downstream.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id'                        => $this->id->value,
            'name'                      => $this->name,
            'id_uppercat'               => $this->idUppercat?->value,
            'comment'                   => $this->comment,
            'dir'                       => $this->dir,
            'rank'                      => $this->rank,
            'status'                    => $this->status->value,
            'site_id'                   => $this->siteId,
            'visible'                   => $this->visible ? 1 : 0,
            'representative_picture_id' => $this->representativePictureId,
            'uppercats'                 => $this->uppercats,
            'commentable'               => $this->commentable ? 1 : 0,
            'global_rank'               => $this->globalRank,
            'image_order'               => $this->imageOrder,
            'permalink'                 => $this->permalink?->value,
            'lastmodified'              => $this->lastModified->value,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function nullableStr(array $row, string $key): ?string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        $v = $row[$key] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    /** @param array<string, mixed> $row */
    private static function bool(array $row, string $key, bool $default): bool
    {
        $v = $row[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v !== 0;
        }
        return $default;
    }
}
