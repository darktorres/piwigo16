<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

use Piwigo\Admin\PluginMaintain;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Plugins\LoadedPluginRegistry;

final class PluginService
{
    public function __construct(
        private readonly PluginRepository $repo,
    ) {
    }

    /** @return array<array<string,mixed>> */
    public function getDbPlugins(?string $state = '', ?string $id = ''): array
    {
        return $this->repo->findAll($state, $id);
    }

    /** @param array<string,mixed> $plugin */
    public function loadPlugin(array $plugin): void
    {
        $pluginId  = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
        $fileName  = PHPWG_PLUGINS_PATH . $pluginId . '/main.inc.php';
        if (file_exists($fileName)) {
            $this->autoupdatePlugin($plugin);
            LoadedPluginRegistry::register($pluginId, $plugin);
            include_once($fileName);
        }
    }

    /** @param array<string,mixed> $plugin */
    public function autoupdatePlugin(array &$plugin): void
    {
        $pluginId  = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
        $fh        = fopen(PHPWG_PLUGINS_PATH . $pluginId . '/main.inc.php', 'r');
        $fsVersion = null;
        $i         = -1;

        while ($fh !== false && ($line = fgets($fh)) !== false && $fsVersion == null && $i < 10) {
            $i++;
            if ($i < 2) {
                continue;
            }
            if (preg_match('/Version:\s*([\w.-]+)/', $line, $matches)) {
                $fsVersion = $matches[1];
            }
        }

        if ($fh !== false) {
            fclose($fh);
        }

        $pluginVersion = is_scalar($plugin['version']) ? (string) $plugin['version'] : '';
        if ($fsVersion != null && (
            $fsVersion == 'auto' || $pluginVersion == 'auto' ||
              safe_version_compare($pluginVersion, $fsVersion, '<')
        )
        ) {
            $oldVersion = $pluginVersion;
            $newVersion = $fsVersion;

            $plugin['version'] = $fsVersion;

            $maintainFile = PHPWG_PLUGINS_PATH . $pluginId . '/maintain.class.php';

            if (file_exists($maintainFile)) {
                include_once($maintainFile);

                $classname = str_replace('-', '_', $pluginId) . '_maintain';

                if (class_exists($classname) && is_a($classname, PluginMaintain::class, true)) {
                    // Dynamic instantiation must stay in include/ — delegate to the factory helper
                    $pluginMaintain = instantiate_plugin_maintain($classname, $pluginId);
                    $errors         = &PageState::current()->errors;
                    $pluginMaintain->update($oldVersion, $fsVersion, $errors);
                }
            }

            if ($newVersion != $oldVersion) {
                $this->repo->updateVersion($pluginId, $fsVersion);
                pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'autoupdate', ['plugin_id' => $pluginId, 'from_version' => $oldVersion, 'to_version' => $newVersion]);
            }
        }
    }

    public function loadPlugins(): void
    {
        LoadedPluginRegistry::init();
        if (Config::enablePlugins()) {
            foreach ($this->getDbPlugins('active') as $plugin) {
                $this->loadPlugin($plugin);
            }
            trigger_notify('plugins_loaded');
        }
    }
}
