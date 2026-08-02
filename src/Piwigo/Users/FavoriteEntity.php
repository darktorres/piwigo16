<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `favorites` table (per-user favorited images) -- composite PK
 * (userId, imageId), no other columns. No `repositoryClass`: `UserRepository`
 * (this table's sole real owner in this migration) queries it directly via
 * DQL/QueryBuilder rather than through a dedicated repository class, same
 * shape as {@see \Piwigo\Tag\ImageTagEntity}.
 *
 * `userId` uses the existing `user_id` custom Doctrine Type. `imageId`
 * stays plain int -- no `ImageIdType` exists yet, same "FK into an
 * un-VO'd domain stays raw" call {@see \Piwigo\Tag\ImageTagEntity}
 * already made.
 */
#[ORM\Entity]
#[ORM\Table(name: 'favorites')]
final class FavoriteEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'image_id', type: 'integer')]
        public int $imageId,
    ) {}
}
