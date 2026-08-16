<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\FormatId;
use Piwigo\Common\ValueObject\ImageId;

/**
 * Maps the `image_format` table. Owned by {@see ImageRepository}, same as
 * {@see ImageEntity}. `formatId` is `FormatId`-typed -- its own primary
 * key, also referenced by `history.format_id`
 * ({@see \Piwigo\History\HistoryEntity::$formatId}).
 * `Image\Projection\ImageFormat::$formatId` stays plain `int`,
 * unwrapping `->value` in `fromEntity()`.
 */
#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[ORM\Table(name: 'image_format')]
final class ImageFormatEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'format_id', type: 'format_id')]
    public ?FormatId $formatId = null;

    public function __construct(
        #[ORM\Column(name: 'image_id', type: 'image_id')]
        public ImageId $imageId,
        #[ORM\Column(type: 'string', length: 255)]
        public string $ext,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $filesize,
    ) {}
}
