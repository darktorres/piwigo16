<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * Maps the `image_category` table (image-to-album membership) --
 * composite PK (imageId, categoryId), plus a mutable `?int $rank`.
 *
 * Placed in `Piwigo\Image` (L2aCoreDomain) rather than `Piwigo\Category`:
 * `Image` is by far the heaviest real consumer, and every other real
 * consumer (`Category`, same layer; `Comment`/`Rate`, L2bExtendedDomain)
 * can legally depend on it from here per `deptrac.yaml`'s ruleset.
 *
 * `imageId` stays plain int, same "FK into an un-VO'd domain stays raw"
 * call {@see \Piwigo\Tag\ImageTagEntity} already made -- no `ImageIdType`
 * exists yet. `categoryId` uses the existing `category_id` custom
 * Doctrine Type, matching {@see \Piwigo\Group\GroupAccessEntity}'s own
 * convention for a mapped foreign id.
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
 */
#[ORM\Entity]
#[ORM\Table(name: 'image_category')]
final class ImageCategoryEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'image_id', type: 'integer')]
        public int $imageId,
        #[ORM\Id]
        #[ORM\Column(name: 'category_id', type: 'category_id')]
        public CategoryId $categoryId,
        #[ORM\Column(name: '`rank`', type: 'integer', nullable: true)]
        public ?int $rank,
    ) {}
}
