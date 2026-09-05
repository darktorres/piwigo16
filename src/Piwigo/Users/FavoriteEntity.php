<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `favorites` table (per-user favorited images) -- composite PK
 * (userId, imageId), no other columns. No `repositoryClass`: `UserRepository`
 * (this table's sole real owner in this migration) queries it directly via
 * DQL/QueryBuilder rather than through a dedicated repository class, same
 * shape as {@see \Piwigo\Tag\ImageTagEntity}.
 *
 * `userId` uses the existing `user_id` custom Doctrine Type; `imageId`
 * uses `image_id`, same as {@see \Piwigo\Tag\ImageTagEntity::$imageId}.
 * `UserRepository`'s own favorites methods never hydrate a full entity
 * (`getArrayResult()`/`getResult()`), but `addFavorite()`/`isFavorite()`
 * do take real `UserId`/`ImageId` parameters (P51-R) and bind them
 * directly (`setParameter('imageId', $imageId)`, no explicit
 * `ParameterType`), letting this Type's own conversion do the work --
 * the plain-`int`/`list<int>` methods elsewhere in that file
 * (`deleteFavoritesForImages()`/`findFavoriteImageIds()`) are a
 * separate, still-untyped surface this Type doesn't reach yet.
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
        #[ORM\Column(name: 'image_id', type: 'image_id')]
        public ImageId $imageId,
    ) {}
}
