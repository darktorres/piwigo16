<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Paths;
use Piwigo\Users\CurrentUser;

/**
 * The filesystem-scan + DB-state merge behind the plugin list -- shared by
 * `Admin\MaintenanceEnvPageRenderer` (server-side render) and
 * `Controller\Api\Extensions\PluginListController` (`GET /api/v1/plugins`).
 */
final readonly class PluginListBuilder
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<array{id: string, name: string, version: string, state: string, description: string}>
     */
    public function build(): array
    {
        $fs_plugins = new ExtensionScanner()
            ->scanPlugins($this->paths, $this->currentUser, $this->currentConfig);
        // Piwigo\Html\HtmlService::nameCompare() (HtmlRenderingInterface's
        // own real, still-generic array<string, mixed> $a/$b contract)
        // doesn't fit a real PluginScanRow object directly -- inlined here
        // rather than wrapping each row back into an array just to satisfy
        // that signature, same strcmp()-on-strtolower() logic.
        uasort($fs_plugins, static fn (PluginScanRow $a, PluginScanRow $b): int => strcmp(strtolower($a->name), strtolower($b->name)));
        $db_plugins_by_id = new ExtensionRepository($this->entityManager)
            ->findAllPlugins();
        $plugin_list = [];

        foreach ($fs_plugins as $plugin_id => $fs_plugin) {
            $state = $db_plugins_by_id[$plugin_id]->state ?? 'uninstalled';

            $plugin_list[] = [
                'id' => $plugin_id,
                'name' => $fs_plugin->name,
                'version' => $fs_plugin->version,
                'state' => $state,
                'description' => $fs_plugin->description,
            ];
        }

        return $plugin_list;
    }
}
