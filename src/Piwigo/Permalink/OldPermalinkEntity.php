<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;

/**
 * Maps the `old_permalinks` table (`piwigo_old_permalinks` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time)
 * -- retired album permalinks, kept to block reuse and shown on the admin
 * permalinks page. `permalink` is the table's own real PRIMARY KEY (see
 * `install/piwigo_structure-mysql.sql`), not an auto-increment surrogate
 * id, same "application-assigned string id" shape as `Feed\FeedEntity`.
 *
 * Further SQL-modernization audit, Item 16B: newly mapped -- this table
 * was deliberately never entity-mapped anywhere in the campaign until
 * now (see the former {@see \Piwigo\Permalink\PermalinkRepository} class
 * docblock, since corrected). No dedicated `repositoryClass` -- unlike
 * most entities, this one is queried from `Permalink\PermalinkRepository`
 * alongside the unrelated `Category\CategoryEntity` reads that repository
 * already owns, not resolved via `$em->getRepository()`.
 *
 * The sole Doctrine entity mapped to this table -- a duplicate,
 * `Piwigo\Category\OldPermalinkEntity`, used to map this exact same
 * table independently (with `catId` already CategoryId-typed there, but
 * not `permalink`), registering two unrelated entities for
 * `old_permalinks` at once, each with its own identity map and no way
 * for a write through one to invalidate a cached read through the
 * other; it has been removed. `catId`/`permalink` both use their own
 * custom Doctrine Types.
 */
#[ORM\Entity]
#[ORM\Table(name: 'old_permalinks')]
final class OldPermalinkEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'permalink', length: 64)]
        public Permalink $permalink,
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
