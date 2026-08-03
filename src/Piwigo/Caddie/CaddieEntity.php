<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `caddie` table (`piwigo_caddie` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time)
 * -- composite PK (userId, elementId), no other columns.
 * {@see CaddieRepository} is this table's sole real owner.
 *
 * `elementId` stays plain int -- no `ImageIdType` exists yet, same "FK
 * into an un-VO'd domain stays raw" call {@see \Piwigo\Tag\ImageTagEntity}/
 * {@see \Piwigo\Image\LoungeEntity} already made.
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
        #[ORM\Column(name: 'element_id', type: 'integer')]
        public int $elementId,
    ) {}
}
