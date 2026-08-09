<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Maps the `search` table (`piwigo_search` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Named to avoid colliding with the existing {@see \Piwigo\Search\
 * Projection\Search} DTO, which stays the return shape every real caller
 * already consumes -- repository methods build it from this entity's
 * properties directly rather than round-tripping through
 * `Search::fromRow()`'s raw-row path.
 *
 * `createdBy`/`forkedFrom` stay plain `?int` -- FK into the un-VO'd
 * `users` domain and a self-FK respectively, same "foreign-key-shaped
 * column into an un-VO'd domain stays raw" call as
 * {@see \Piwigo\Comment\CommentEntity::$authorId}. `createdOn` is
 * `SqlDateTime`-typed -- nullable for `Ws\PwgCore::
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
        #[ORM\Column(name: 'created_by', type: 'integer', nullable: true)]
        public ?int $createdBy,
        #[ORM\Column(name: 'forked_from', type: 'integer', nullable: true)]
        public ?int $forkedFrom,
        /**
         * @var array<string, mixed>|null
         */
        #[ORM\Column(type: 'json', nullable: true)]
        public ?array $rules,
    ) {}
}
