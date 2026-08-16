<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\AuthKeyId;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\FormatId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SearchId;
use Piwigo\Common\ValueObject\SqlDate;
use Piwigo\Common\ValueObject\SqlTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `history` table. `date`/`time` are `SqlDate`/`SqlTime`-typed -- the
 * one real write path (`insert()`) traces to an `Env::now()`-derived value.
 * `history_summary` (this repository's other owned table) is mapped as {@see
 * HistorySummaryEntity}; only `findSummaryRowsForHierarchy()`'s own dynamic
 * composite-nullable-key WHERE doesn't fit a clean single-row shape.
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
 *
 * `searchId` is `SearchId`-typed -- `fk_history_search_id`, `ON DELETE SET
 * NULL`. `search.id` itself stays a plain `?int` primary key (out of
 * `0.3`'s scope, same as `sites.id`). `HistoryRepository::search()`'s own
 * `getArrayResult()` unwraps it via `instanceof`, same Gotcha #1 shape as
 * `userId`/`categoryId`/`imageId` beside it.
 *
 * `formatId` is `FormatId`-typed (`fk_history_format_id`, `ON DELETE SET
 * NULL`) -- unlike `searchId`, no `HistoryRepository` DQL site selects it,
 * only `insert()` writes it. `authKeyId` is `AuthKeyId`-typed
 * (`fk_history_auth_key_id`, `ON DELETE SET NULL`) the same way.
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
        #[ORM\Column(type: 'sql_date', length: 10, nullable: true)]
        public ?SqlDate $date,
        #[ORM\Column(type: 'sql_time', length: 8)]
        public SqlTime $time,
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Column(name: 'IP', type: 'ip_address_graceful', length: 39)]
        public ?IpAddress $ip,
        #[ORM\Column(type: 'string', length: 20, nullable: true)]
        public ?string $section,
        #[ORM\Column(name: 'category_id', type: 'category_id', nullable: true)]
        public ?CategoryId $categoryId,
        #[ORM\Column(name: 'search_id', type: 'search_id', nullable: true)]
        public ?SearchId $searchId,
        #[ORM\Column(name: 'tag_ids', type: 'string', length: 50, nullable: true)]
        public ?string $tagIds,
        #[ORM\Column(name: 'image_id', type: 'image_id', nullable: true)]
        public ?ImageId $imageId,
        #[ORM\Column(name: 'image_type', type: 'string', length: 10, nullable: true, enumType: HistoryImageType::class)]
        public ?HistoryImageType $imageType,
        #[ORM\Column(name: 'format_id', type: 'format_id', nullable: true)]
        public ?FormatId $formatId,
        #[ORM\Column(name: 'auth_key_id', type: 'auth_key_id', nullable: true)]
        public ?AuthKeyId $authKeyId,
    ) {}
}
