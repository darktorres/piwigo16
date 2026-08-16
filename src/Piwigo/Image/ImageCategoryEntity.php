<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Category\CategoryEntity;

/**
 * Maps the `image_category` table (image-to-album membership) --
 * composite identity *through* two owning-side associations, plus a
 * mutable `?int $rank`.
 *
 * Placed in `Piwigo\Image` (L2aCoreDomain) rather than `Piwigo\Category`:
 * `Image` is by far the heaviest real consumer, and every other real
 * consumer (`Category`, same layer; `Comment`/`Rate`, L2bExtendedDomain)
 * can legally depend on it from here per `deptrac.yaml`'s ruleset.
 *
 * `image`/`category` are real `#[ORM\Id] #[ORM\ManyToOne]` associations,
 * not scalar VOs -- Doctrine's textbook shape for a many-to-many with an
 * extra column (`rank`), which a plain `#[ManyToMany]` can't carry.
 * `nullable`/`referencedColumnName` are deliberately never specified on
 * either `#[ORM\JoinColumn]` -- Doctrine forces `nullable: false`
 * unconditionally for a to-one association that's part of the
 * identifier and raises a deprecation warning if it's specified at all
 * (see `0.3`'s own mechanics section; the same trap `UserInfoEntity::
 * $user` already hit).
 *
 * `ImageEntity::$imageCategories`/`CategoryEntity::$imageCategories` are
 * the inverse `#[ORM\OneToMany]` sides -- collection-valued, so (unlike a
 * to-one inverse) they stay genuinely uninitialized until iterated
 * (`PersistentCollection`), safe to declare. They exist so DQL joining
 * *from* `ImageEntity`/`CategoryEntity` *into* this table (roughly half
 * of this table's real join sites) has an association path to join
 * through, not just the direction starting `FROM ImageCategoryEntity`.
 * Both owning-side `#[ORM\ManyToOne]`s here carry `inversedBy:
 * 'imageCategories'` -- required whenever the other side declares
 * `mappedBy`; Doctrine's `SchemaValidator` (exercised by
 * `tests/Integration/SchemaParityTest.php`) fails mapping validation
 * without it, even though the association itself works fine at query
 * time either way.
 *
 * `rank`'s column name is explicitly backtick-quoted (Doctrine's own
 * documented mechanism for a reserved SQL keyword column) -- `RANK` is a
 * reserved word as of MySQL 8.0.2 (window functions), and the schema's
 * own `CREATE TABLE` already quotes it for the same reason. Without the
 * explicit quoting, `SELECT`/`WHERE` on `ic.rank` compiles fine (DQL's
 * own identifier-quoting there already handles it), but a DQL `UPDATE
 * ... SET ic.rank = ...` does not -- this only surfaces as a
 * `SyntaxErrorException` at runtime when
 * {@see \Piwigo\Image\ImageRepository::updateRankForImageInCategory()}/
 * {@see \Piwigo\Image\ImageRepository::incrementRanksFromForCategory()}
 * run, not via static analysis.
 *
 * No production construction site exists: every real `image_category`
 * write goes through {@see \Piwigo\Db\BatchWriter::massInsert()} (raw
 * DBAL, bulk insert) via {@see \Piwigo\Image\ImageRepository::
 * massInsertImageCategoryLinks()} -- this entity's constructor is only
 * ever called from tests.
 */
#[ORM\Entity]
#[ORM\Table(name: 'image_category')]
final class ImageCategoryEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'imageCategories')]
        #[ORM\JoinColumn(name: 'image_id')]
        public ImageEntity $image,
        #[ORM\Id]
        #[ORM\ManyToOne(inversedBy: 'imageCategories')]
        #[ORM\JoinColumn(name: 'category_id')]
        public CategoryEntity $category,
        #[ORM\Column(name: '`rank`', type: 'integer', nullable: true)]
        public ?int $rank,
    ) {}
}
