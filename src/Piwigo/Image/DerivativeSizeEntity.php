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
 *
 * `enabled` is `int` (1/0), not `bool` -- the real column is `smallint`
 * (matching the hand-maintained install/piwigo_structure-mysql.sql's own
 * choice, not this codebase's usual `tinyint(1)` convention for a real
 * boolean flag; Version20260804122302's own docblock explains why the
 * pgsql-support pass's baseline migration preserves that distinction
 * rather than reinterpreting it). Real, previously-latent bug found live
 * this session: a `#[ORM\Column(type: 'boolean')]` mapping against a
 * genuinely `smallint` column was already a real mismatch on MySQL too,
 * just silently tolerated there (Doctrine's own MySQL boolean-parameter
 * conversion happens to produce a plain integer 1/0, valid smallint
 * input) -- Postgres's own boolean conversion produces the literal
 * strings 't'/'f', which Postgres correctly rejects as invalid smallint
 * input ("invalid input syntax for type smallint: \"t\""), surfaced only
 * once a real install actually ran against a live Postgres server.
 */
#[ORM\Entity(repositoryClass: DerivativeSizeRepository::class)]
#[ORM\Table(name: 'derivative_size')]
final class DerivativeSizeEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        public string $name,
        #[ORM\Column(type: 'smallint')]
        public int $enabled,
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
