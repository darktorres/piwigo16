<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `plugin_migrations` table (`piwigo_plugin_migrations` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Minimal, auto-recorded ledger of "this plugin ran this version" --
 * written by ExtensionLifecycle after every successful plugin install/
 * update, purely for history/audit. Deliberately not a real migration
 * runner: PluginMaintain authors get nothing new to implement, and no
 * per-migration-file tracking exists -- a real versioned-migration system
 * would reintroduce, scoped to plugins, exactly the fine-grained
 * in-place-upgrade machinery this codebase's own docs describe
 * deliberately ripping out of core.
 */
#[ORM\Entity(repositoryClass: PluginMigrationRepository::class)]
#[ORM\Table(name: 'plugin_migrations')]
final class PluginMigrationEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'plugin_id', type: 'string', length: 64)]
        public string $pluginId,
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 191)]
        public string $version,
        #[ORM\Column(name: 'executed_at', type: 'string', length: 19)]
        public string $executedAt,
    ) {}
}
