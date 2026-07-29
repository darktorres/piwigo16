<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `derivative_size` table (`piwigo_derivative_size` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * One row per named derivative size (`name` is one of ImageStdParams's own
 * size-type constants, e.g. ImageStdParams::THUMB). Replaces
 * ImageStdParams's former `type_map`/`disabled_type_map` split -- the
 * `enabled` column unifies what used to be two separate piwigo_config keys
 * (`derivatives`'s `d` key vs `disabled_derivatives`) into one table.
 * `maxWidth`/`maxHeight`/`maxCrop`/`minWidth`/`minHeight` mirror
 * SizingParams's own fields; `sharpen`/`lastModTime` mirror DerivativeParams's
 * own fields -- together they fully reconstruct a real DerivativeParams/
 * SizingParams pair without ever unserialize()-ing a PHP object from the
 * database.
 */
#[ORM\Entity(repositoryClass: DerivativeSizeRepository::class)]
#[ORM\Table(name: 'derivative_size')]
final class DerivativeSizeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public string $name,
        #[ORM\Column(type: 'boolean')]
        public bool $enabled,
        #[ORM\Column(name: 'max_width', type: 'integer')]
        public int $maxWidth,
        #[ORM\Column(name: 'max_height', type: 'integer')]
        public int $maxHeight,
        #[ORM\Column(name: 'max_crop', type: 'decimal', precision: 5, scale: 4)]
        public string $maxCrop,
        #[ORM\Column(name: 'min_width', type: 'integer', nullable: true)]
        public ?int $minWidth,
        #[ORM\Column(name: 'min_height', type: 'integer', nullable: true)]
        public ?int $minHeight,
        #[ORM\Column(type: 'decimal', precision: 5, scale: 4)]
        public string $sharpen,
        #[ORM\Column(name: 'last_mod_time', type: 'integer')]
        public int $lastModTime,
    ) {}
}
