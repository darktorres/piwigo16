<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `search` table. Named to avoid colliding with the existing {@see
 * \Piwigo\Search\ Projection\Search} DTO, which stays the return shape every
 * real caller already consumes -- repository methods build it from this
 * entity's properties directly rather than round-tripping through
 * `Search::fromRow()`'s raw-row path.
 *
 * `createdBy` is `UserId`-typed -- `fk_search_created_by` is a real
 * constraint onto `users.id`. `forkedFrom` stays plain `?int`: a self-FK
 * onto this same table's own `id`, which stays a plain `?int` primary key
 * (out of `0.3`'s scope, see the SQL-modernization plan). `createdOn` is
 * `SqlDateTime`-typed -- nullable for `Ws\Core::
 * historySearch()`'s ephemeral, metadata-less inserts (no user-facing
 * permalink, never forked); `SearchService::saveSearch()`'s own real
 * inserts trace to an `Env::now()`-derived value. `rules` maps as native Doctrine
 * `json` (the column really is JSON), same precedent as
 * {@see \Piwigo\Users\UserInfoEntity::$preferences} -- Doctrine decodes
 * it directly, no `ArrayHelper::safeJsonDecode()`/manual `json_decode()`
 * step needed on read.
 */
#[ORM\Entity]
#[ORM\Table(name: 'search')]
final class SavedSearchEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'search_uuid', type: 'string', length: 23, nullable: true)]
        public ?string $searchUuid,
        #[ORM\Column(name: 'created_on', type: 'sql_datetime', length: 19, nullable: true)]
        public ?SqlDateTime $createdOn,
        #[ORM\Column(name: 'created_by', type: 'user_id', nullable: true)]
        public ?UserId $createdBy,
        #[ORM\Column(name: 'forked_from', type: 'integer', nullable: true)]
        public ?int $forkedFrom,
        /**
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $rules,
    ) {}
}
