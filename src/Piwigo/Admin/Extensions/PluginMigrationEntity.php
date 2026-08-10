<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\PluginId;

/**
 * Maps the `plugin_migrations` table. Minimal, auto-recorded ledger of "this
 * plugin ran this version" -- written by ExtensionLifecycle after every
 * successful plugin install/ update, purely for history/audit. Deliberately
 * not a real migration runner: PluginMaintain authors get nothing new to
 * implement, and no per-migration-file tracking exists -- a real
 * versioned-migration system would reintroduce, scoped to plugins, exactly the
 * fine-grained in-place-upgrade machinery this codebase's own docs describe
 * deliberately ripping out of core.
 *
 * `$pluginId` uses the `plugin_id` custom Doctrine Type
 * (`Piwigo\Common\ValueObject\PluginId`) -- this table is plugin-only (no
 * theme equivalent), unlike ExtensionRepository's own
 * deliberately-generic plugins/themes/languages wrapper, so it's in
 * scope here despite living under Admin\Extensions.
 */
#[ORM\Entity(repositoryClass: PluginMigrationRepository::class)]
#[ORM\Table(name: 'plugin_migrations')]
final class PluginMigrationEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'plugin_id', type: 'plugin_id', length: 64)]
        public PluginId $pluginId,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 191)]
        public string $version,
        #[ORM\Column(name: 'executed_at', type: 'string', length: 19)]
        public string $executedAt,
    ) {}
}
