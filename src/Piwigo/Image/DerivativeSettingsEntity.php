<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `derivative_settings` table. Single logical row, id=1 by convention
 * -- the table itself has no DB-level CHECK/default enforcing that (confirmed
 * against install/piwigo_structure-mysql.sql: `id` is a bare smallint PK, no
 * AUTO_INCREMENT, no default), so DerivativeSettingsRepository is what
 * enforces the singleton shape, not the schema. Replaces ImageStdParams's
 * former `derivatives` config blob (`ConfigService::
 * OBJECT_SERIALIZED_PARAMS`) -- `watermarkJson`/`customJson` hold the same
 * data `WatermarkParams`/`ImageStdParams::$custom` used to carry via
 * serialize(), now as real JSON instead of an opaque PHP-object blob.
 */
#[ORM\Entity(repositoryClass: DerivativeSettingsRepository::class)]
#[ORM\Table(name: 'derivative_settings')]
final class DerivativeSettingsEntity
{
    public const int SINGLETON_ID = 1;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(name: 'default_quality', type: 'integer')]
        public int $defaultQuality,
        /**
         * @var array<string, mixed>
         */
        #[ORM\Column(name: 'watermark_json', type: 'json')]
        public array $watermarkJson,
        /**
         * @var array<string, int>
         */
        #[ORM\Column(name: 'custom_json', type: 'json')]
        public array $customJson,
    ) {}
}
