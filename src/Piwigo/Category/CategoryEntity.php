<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Override;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Db\HasLastModified;
use Piwigo\Image\ImageCategoryEntity;
use Piwigo\Image\ImageEntity;
use Piwigo\Site\SiteEntity;

/**
 * Maps the `categories` table. `lastmodified` is `SqlDateTime`-typed -- `NOT
 * NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` means the DB
 * server always populates a real, well-formed timestamp. `new
 * CategoryEntity(...)` is never constructed in PHP anywhere in this codebase
 * (every real category row is inserted via raw DBAL).
 * `Category\Projection\Category` keeps its own plain-string `lastmodified`
 * convention.
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
 * `representativePicture` and `site` are both real `#[ORM\ManyToOne]`
 * associations (`fk_categories_representative_picture_id`/
 * `fk_categories_site_id`), not scalar VOs -- the schema's own `ON DELETE
 * SET NULL`/`ON DELETE CASCADE` is the only referential authority (no
 * `#[JoinColumn(onDelete: ...)]` here, see `0.3`'s "No ORM cascades").
 * `nullable`/`referencedColumnName` are both left unspecified on either
 * `#[ORM\JoinColumn]` deliberately -- they resolve to `true`/`'id'` on
 * their own. `Category\Projection\Category::$representativePictureId`/
 * `$siteId` both stay plain `?int` regardless, unwrapping
 * `$entity->representativePicture?->id?->value`/`$entity->site?->id` in
 * `fromEntity()` (`SiteEntity::$id` is already a plain `?int`, unlike
 * `ImageEntity`/`CategoryEntity`'s VO-typed ids, so no further `->value`
 * unwrap is needed for `site`) -- `->id` on an uninitialized to-one proxy
 * never triggers a query, since `ProxyFactory` pre-populates identifier
 * fields at construction time and marks them as skipped from lazy
 * initialization.
 *
 * `site` required moving `Piwigo\Site`/`Piwigo\Metadata`/`Piwigo\Activity`
 * from `L2bExtendedDomain` into `L2aCoreDomain` (`deptrac.yaml`) --
 * `Piwigo\Category` (L2a) couldn't otherwise depend on `Piwigo\Site` (L2b).
 * See `deptrac.yaml`'s own L2a comment for the full chain audit.
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
final class CategoryEntity implements HasLastModified
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'category_id')]
    public ?CategoryId $id = null;

    /**
     * Inverse `#[ORM\OneToMany]` side of {@see ImageCategoryEntity::
     * $category} -- collection-valued, so (unlike a to-one inverse) it
     * stays a genuinely uninitialized `PersistentCollection` until
     * iterated. Exists only so DQL joining *from* `CategoryEntity` *into*
     * `image_category` has an association path to join through; never
     * touched as a bare property in application code (see `0.3`'s "keep
     * joins explicit").
     *
     * @var Collection<int, ImageCategoryEntity>
     */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: ImageCategoryEntity::class)]
    public Collection $imageCategories;

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
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'site_id')]
        public ?SiteEntity $site,
        #[ORM\Column(type: 'boolean')]
        public bool $visible,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(name: 'representative_picture_id')]
        public ?ImageEntity $representativePicture,
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
    ) {
        $this->imageCategories = new ArrayCollection();
    }

    #[Override]
    public function touchLastModified(SqlDateTime $now): void
    {
        $this->lastmodified = $now;
    }
}
