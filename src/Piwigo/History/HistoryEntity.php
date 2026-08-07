<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;

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
 *
 * `ip` is `NOT NULL DEFAULT ''` at the DB level, not nullable -- `?IpAddress`
 * here uses `ip_address_graceful` (not the strict `ip_address` Type
 * {@see \Piwigo\Activity\ActivityEntity}/{@see \Piwigo\Audit\AuditLogEntity}
 * use for their own genuinely-nullable columns): `''` round-trips to/from
 * PHP `null` instead of throwing, since `REMOTE_ADDR` is legitimately
 * unavailable on some real request paths (see `IpAddress`'s own docblock).
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
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'IP', type: 'ip_address_graceful', length: 39)]
        public ?IpAddress $ip,
        #[ORM\Column(type: 'string', length: 20, nullable: true)]
        public ?string $section,
        #[ORM\Column(name: 'category_id', type: 'category_id', nullable: true)]
        public ?CategoryId $categoryId,
        #[ORM\Column(name: 'search_id', type: 'integer', nullable: true)]
        public ?int $searchId,
        #[ORM\Column(name: 'tag_ids', type: 'string', length: 50, nullable: true)]
        public ?string $tagIds,
        #[ORM\Column(name: 'image_id', type: 'image_id', nullable: true)]
        public ?ImageId $imageId,
        #[ORM\Column(name: 'image_type', type: 'string', length: 10, nullable: true, enumType: HistoryImageType::class)]
        public ?HistoryImageType $imageType,
        #[ORM\Column(name: 'format_id', type: 'integer', nullable: true)]
        public ?int $formatId,
        #[ORM\Column(name: 'auth_key_id', type: 'integer', nullable: true)]
        public ?int $authKeyId,
    ) {}
}
