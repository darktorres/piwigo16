<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;

/**
 * Maps the `lounge` table (pending image-to-album associations, applied
 * in bulk by ImageService::emptyLounge) -- composite PK (imageId,
 * categoryId), no other columns. No `repositoryClass`: `ImageRepository`
 * (this table's sole real owner) queries it directly via DQL/QueryBuilder,
 * same shape as {@see \Piwigo\Tag\ImageTagEntity}.
 *
 * `imageId` stays plain int -- no `ImageIdType` exists yet, same "FK
 * into an un-VO'd domain stays raw" call {@see \Piwigo\Tag\ImageTagEntity}
 * already made. `categoryId` uses the existing `category_id` custom
 * Doctrine Type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lounge')]
final class LoungeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'image_id', type: 'integer')]
        public int $imageId,
        #[ORM\Id]
        #[ORM\Column(name: 'category_id', type: 'category_id')]
        public CategoryId $categoryId,
    ) {}
}
