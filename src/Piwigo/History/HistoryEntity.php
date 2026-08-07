<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `history` table (`piwigo_history` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `date`/`time` stay plain string, not \DateTimeImmutable -- every real
 * consumer wants the raw DB DATE/TIME string form. `history_summary`
 * (this repository's other owned table) is mapped as
 * {@see HistorySummaryEntity}; only `findSummaryRowsForHierarchy()`'s own
 * dynamic composite-nullable-key WHERE doesn't fit a clean single-row
 * shape.
 *
 * `imageType` is `HistoryImageType` (a native Doctrine `enumType` column)
 * -- `section` deliberately stays a plain `?string` instead, see
 * {@see HistoryRepository::getSectionEnumOptions()}'s own docblock for why
 * (plugin-widened at runtime, not a closed set).
 */
#[ORM\Entity(repositoryClass: HistoryRepository::class)]
#[ORM\Table(name: 'history')]
final class HistoryEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 10, nullable: true)]
        public ?string $date,
        #[ORM\Column(type: 'string', length: 8)]
        public string $time,
        #[ORM\Column(name: 'user_id', type: 'integer')]
        public int $userId,
        #[ORM\Column(name: 'IP', type: 'string', length: 39)]
        public string $ip,
        #[ORM\Column(type: 'string', length: 20, nullable: true)]
        public ?string $section,
        #[ORM\Column(name: 'category_id', type: 'integer', nullable: true)]
        public ?int $categoryId,
        #[ORM\Column(name: 'search_id', type: 'integer', nullable: true)]
        public ?int $searchId,
        #[ORM\Column(name: 'tag_ids', type: 'string', length: 50, nullable: true)]
        public ?string $tagIds,
        #[ORM\Column(name: 'image_id', type: 'integer', nullable: true)]
        public ?int $imageId,
        #[ORM\Column(name: 'image_type', type: 'string', length: 10, nullable: true, enumType: HistoryImageType::class)]
        public ?HistoryImageType $imageType,
        #[ORM\Column(name: 'format_id', type: 'integer', nullable: true)]
        public ?int $formatId,
        #[ORM\Column(name: 'auth_key_id', type: 'integer', nullable: true)]
        public ?int $authKeyId,
    ) {}
}
