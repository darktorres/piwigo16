<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `categories` table (`piwigo_categories` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `lastmodified` stays plain string, not \DateTimeImmutable -- matches
 * Category\Projection\Category's own already-documented decision.
 *
 * `status` is `CategoryStatus` (native Doctrine `enumType` column), same
 * pattern and same real gotcha as `Piwigo\Users\UserInfoEntity::$status`
 * (Phase 5 Item 21): `enumType` hydration applies to *any* scalar/array
 * DQL select of the field, not just full-entity reads, so every
 * `CategoryRepository` method selecting `c.status` via
 * `getArrayResult()`/`getSingleColumnResult()` was audited and updated to
 * unwrap `->value` right after fetch, preserving each method's
 * pre-existing plain-string return contract. WHERE/SET parameter binding
 * (`setParameter('status', 'private')`) is unaffected either way, so
 * those call sites are untouched.
 *
 * Only a handful of CategoryRepository's 65 methods go through this
 * entity -- the large majority are bulk id-list operations against a
 * caller-supplied dynamic SQL fragment (permission conditions, ORDER BY
 * clauses), a dynamically-named table/column pair (findOrphanedColumnValues/
 * deleteRowsWhereColumnIn/deleteInconsistentAccess), or a cross-domain
 * table this repository doesn't own -- those stay plain DBAL via
 * $this->getEntityManager()->getConnection(), same "mixed repository"
 * shape Image/Tag's own conversions already established.
 *
 * `image_category` was previously deliberately left unmapped here,
 * reasoning that a shared entity's cross-repository coordination cost
 * wasn't worth it for the "couple of clean exceptions" among its
 * touches. Re-audited (Item 14 Sub-phase B1) and reversed: `group_access`/
 * `user_access` already prove a join-table entity works fine shared
 * across repositories, and a fuller re-read found considerably more than
 * a couple of clean single-table candidates. Now mapped as {@see
 * \Piwigo\Image\ImageCategoryEntity}, placed in `Piwigo\Image` (the
 * heaviest real consumer) rather than here.
 *
 * `rank`'s column name is explicitly backtick-quoted -- `RANK` is a
 * reserved SQL keyword as of MySQL 8.0.2 (window functions), and a real
 * DQL `UPDATE ... SET` against an unquoted `rank` property elsewhere in
 * this migration ({@see \Piwigo\Image\ImageCategoryEntity}'s own `$rank`,
 * same column name/reserved-word shape) hit a genuine `SyntaxErrorException`
 * against the real test DB; this property isn't DQL-`UPDATE`d anywhere
 * today, but fixed proactively rather than leaving the same latent
 * landmine here.
 */
#[ORM\Entity]
#[ORM\Table(name: 'categories')]
final class CategoryEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

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
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public ?string $permalink,
        #[ORM\Column(type: 'string', length: 19)]
        public string $lastmodified,
    ) {}
}
