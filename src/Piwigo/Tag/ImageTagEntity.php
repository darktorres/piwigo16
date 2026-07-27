<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\TagId;

/**
 * Maps the `image_tag` table (Image<->Tag join) -- composite PK
 * (image_id, tag_id), no other columns. No custom repositoryClass:
 * TagRepository (this table's real owner) queries it directly via DQL/
 * QueryBuilder rather than through a dedicated repository class.
 *
 * `tagId` uses the custom TagIdType ({@see \Piwigo\Db\Type\TagIdType}) --
 * `imageId` stays plain int, since ImageId's own domain (Image, Stage A5)
 * isn't VO-integrated yet, same "foreign-key-shaped column into an
 * un-VO'd domain stays raw" call made for CommentEntity::$authorId.
 */
#[ORM\Entity]
#[ORM\Table(name: 'image_tag')]
final class ImageTagEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'image_id', type: 'integer')]
        public int $imageId,
        #[ORM\Id]
        #[ORM\Column(name: 'tag_id', type: 'tag_id')]
        public TagId $tagId,
    ) {}
}
