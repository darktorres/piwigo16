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
 * Only a handful of CategoryRepository's 65 methods go through this
 * entity -- the large majority are bulk id-list operations against a
 * caller-supplied dynamic SQL fragment (permission conditions, ORDER BY
 * clauses), a dynamically-named table/column pair (findOrphanedColumnValues/
 * deleteRowsWhereColumnIn/deleteInconsistentAccess), or a cross-domain
 * table this repository doesn't own -- those stay plain DBAL via
 * $this->getEntityManager()->getConnection(), same "mixed repository"
 * shape Image/Tag's own conversions already established. `image_category`
 * is deliberately never entity-mapped by any repository in this whole
 * migration (unlike `group_access`/`user_access`, see UserAccessEntity) --
 * almost every one of its own touches, across both Image and Category,
 * is a dynamic-fragment or cross-table-join method with no real ORM
 * benefit, and the couple of clean exceptions don't justify the
 * cross-repository coordination a shared entity would need.
 */
#[ORM\Entity(repositoryClass: CategoryRepository::class)]
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
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $rank,
        #[ORM\Column(type: 'string', length: 10)]
        public string $status,
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
