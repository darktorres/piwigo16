<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Maps the `categories` table (`piwigo_categories` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `lastmodified` is `SqlDateTime`-typed -- `NOT NULL DEFAULT
 * CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` means the DB server
 * always populates a real, well-formed timestamp. `new CategoryEntity(...)`
 * is never constructed in PHP anywhere in this codebase (every real
 * category row is inserted via raw DBAL). `Category\Projection\Category`
 * keeps its own plain-string `lastmodified` convention.
 *
 * `status` is `CategoryStatus` (native Doctrine `enumType` column), same
 * gotcha as `Piwigo\Users\UserInfoEntity::$status`: `enumType` hydration
 * applies to *any* scalar/array DQL select of the field, not just
 * full-entity reads, so every `CategoryRepository` method selecting
 * `c.status` via `getArrayResult()`/`getSingleColumnResult()` must unwrap
 * `->value` right after fetch to keep a plain-string return contract.
 * WHERE/SET parameter binding (`setParameter('status', 'private')`) is
 * unaffected either way. `id`/`permalink` are custom-Typed the same way
 * (`category_id`/`permalink`) -- `getArrayResult()` (Gotcha #1) applies
 * both during hydration, so every `CategoryRepository` method selecting
 * `c.id`/`c.permalink` that way must unwrap via `instanceof`;
 * `getSingleColumnResult()` (Gotcha #4, `HYDRATE_SCALAR_COLUMN`) never
 * does, regardless of column.
 *
 * Only a handful of CategoryRepository's 65 methods go through this
 * entity -- the large majority are bulk id-list operations against a
 * caller-supplied dynamic SQL fragment (permission conditions, ORDER BY
 * clauses), a dynamically-named table/column pair (findOrphanedColumnValues/
 * deleteRowsWhereColumnIn/deleteInconsistentAccess), or a cross-domain
 * table this repository doesn't own -- those stay plain DBAL via
 * $this->getEntityManager()->getConnection(), the same mixed-repository
 * shape used for Image/Tag.
 *
 * `image_category` is mapped as {@see \Piwigo\Image\ImageCategoryEntity},
 * placed in `Piwigo\Image` (the heaviest real consumer) rather than here
 * -- `group_access`/`user_access` already show a join-table entity works
 * fine shared across repositories.
 *
 * `rank`'s column name is explicitly backtick-quoted: `RANK` is a
 * reserved SQL keyword as of MySQL 8.0.2 (window functions), and an
 * unquoted `rank` property used in a DQL `UPDATE ... SET` ({@see
 * \Piwigo\Image\ImageCategoryEntity}'s own `$rank`, same column
 * name/reserved-word shape) raises a genuine `SyntaxErrorException`
 * against a real MySQL 8 database. This property isn't DQL-`UPDATE`d
 * anywhere today, but stays quoted proactively to avoid the same latent
 * landmine.
 */
#[ORM\Entity]
#[ORM\Table(name: 'categories')]
final class CategoryEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'category_id')]
    public ?CategoryId $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $name,
        #[ORM\Column(name: 'id_uppercat', type: 'integer', nullable: true)]
        public ?int $idUppercat,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $comment,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $dir,
        #[ORM\Column(name: '`rank`', type: 'integer', nullable: true)]
        public ?int $rank,
        #[ORM\Column(type: 'string', length: 10, enumType: CategoryStatus::class)]
        public CategoryStatus $status,
        #[ORM\Column(name: 'site_id', type: 'integer', nullable: true)]
        public ?int $siteId,
        #[ORM\Column(type: 'boolean')]
        public bool $visible,
        #[ORM\Column(name: 'representative_picture_id', type: 'integer', nullable: true)]
        public ?int $representativePictureId,
        #[ORM\Column(type: 'string', length: 255)]
        public string $uppercats,
        #[ORM\Column(type: 'boolean')]
        public bool $commentable,
        #[ORM\Column(name: 'global_rank', type: 'string', length: 255, nullable: true)]
        public ?string $globalRank,
        #[ORM\Column(name: 'image_order', type: 'string', length: 128, nullable: true)]
        public ?string $imageOrder,
        #[ORM\Column(type: 'permalink', length: 64, nullable: true)]
        public ?Permalink $permalink,
        #[ORM\Column(type: 'sql_datetime', length: 19)]
        public SqlDateTime $lastmodified,
    ) {}
}
