<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * Maps the `old_permalinks` table (retired album permalinks, kept to
 * block reuse and shown on the admin permalinks page) -- natural PK
 * `permalink` (a string, not auto-increment). No `repositoryClass`:
 * `CategoryRepository` (this table's sole real owner) queries it
 * directly via DQL/QueryBuilder, same shape as {@see UserAccessEntity}.
 *
 * `catId` uses the existing `category_id` custom Doctrine Type, matching
 * {@see \Piwigo\Group\GroupAccessEntity}'s own convention. `dateDeleted`/
 * `lastHit` stay plain `?string`, not \DateTimeImmutable -- same "every
 * real consumer wants the raw DB DATETIME string form" reasoning as
 * {@see \Piwigo\Rate\RateEntity::$date}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'old_permalinks')]
final class OldPermalinkEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $permalink,
        #[ORM\Column(name: 'cat_id', type: 'category_id')]
        public CategoryId $catId,
        #[ORM\Column(name: 'date_deleted', type: 'string', length: 19, nullable: true)]
        public ?string $dateDeleted,
        #[ORM\Column(name: 'last_hit', type: 'string', length: 19, nullable: true)]
        public ?string $lastHit,
        #[ORM\Column(type: 'integer')]
        public int $hit,
    ) {}
}
