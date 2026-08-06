<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `caddie` table (`piwigo_caddie` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time)
 * -- composite PK (userId, elementId), no other columns.
 * {@see CaddieRepository} is this table's sole real owner.
 *
 * `elementId` uses the `image_id` custom Doctrine Type, same as
 * {@see \Piwigo\Image\ImageCategoryEntity}/{@see \Piwigo\Image\LoungeEntity}.
 */
#[ORM\Entity(repositoryClass: CaddieRepository::class)]
#[ORM\Table(name: 'caddie')]
final class CaddieEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
        #[ORM\Id]
        #[ORM\Column(name: 'element_id', type: 'image_id')]
        public ImageId $elementId,
    ) {}
}
