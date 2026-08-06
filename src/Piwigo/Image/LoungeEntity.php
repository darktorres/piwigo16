<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;

/**
 * Maps the `lounge` table (pending image-to-album associations, applied
 * in bulk by ImageService::emptyLounge) -- composite PK (imageId,
 * categoryId), no other columns. No `repositoryClass`: `ImageRepository`
 * (this table's sole real owner) queries it directly via DQL/QueryBuilder,
 * same shape as {@see \Piwigo\Tag\ImageTagEntity}.
 *
 * `imageId` uses the `image_id` custom Doctrine Type; `categoryId` uses
 * the existing `category_id` custom Doctrine Type.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lounge')]
final class LoungeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'image_id', type: 'image_id')]
        public ImageId $imageId,
        #[ORM\Id]
        #[ORM\Column(name: 'category_id', type: 'category_id')]
        public CategoryId $categoryId,
    ) {}
}
