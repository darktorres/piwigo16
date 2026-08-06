<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\ImageId;

/**
 * Maps the `image_format` table (`piwigo_image_format` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Owned by {@see ImageRepository}, same as {@see ImageEntity}.
 */
#[ORM\Entity(repositoryClass: ImageRepository::class)]
#[ORM\Table(name: 'image_format')]
final class ImageFormatEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'format_id', type: 'integer')]
    public ?int $formatId = null;

    public function __construct(
        #[ORM\Column(name: 'image_id', type: 'image_id')]
        public ImageId $imageId,
        #[ORM\Column(type: 'string', length: 255)]
        public string $ext,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $filesize,
    ) {}
}
