<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * Maps the `image_category` table (image-to-album membership) --
 * composite PK (imageId, categoryId), plus a mutable `?int $rank`.
 *
 * Further SQL-modernization audit, Item 14 Sub-phase B1: previously
 * deliberately left unmapped ({@see CategoryEntity}'s own docblock)
 * reasoning a shared entity's cross-repository coordination cost wasn't
 * worth it -- re-audited and reversed, since GroupAccessEntity/
 * UserAccessEntity already establish that a join-table entity works
 * fine shared across multiple repositories' own DQL. Placed in
 * `Piwigo\Image` (L2aCoreDomain) rather than `Piwigo\Category`: `Image`
 * is by far the heaviest real consumer, and every other real consumer
 * (`Category`, same layer; `Comment`/`Rate`, L2bExtendedDomain) can
 * legally depend on it from here per `deptrac.yaml`'s ruleset.
 *
 * `imageId` stays plain int, same "FK into an un-VO'd domain stays raw"
 * call {@see \Piwigo\Tag\ImageTagEntity} already made -- no `ImageIdType`
 * exists yet. `categoryId` uses the existing `category_id` custom
 * Doctrine Type, matching {@see \Piwigo\Group\GroupAccessEntity}'s own
 * convention for a mapped foreign id.
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
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $rank,
    ) {}
}
