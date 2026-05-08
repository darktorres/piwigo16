<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

use Piwigo\Admin\PluginMaintain;
use Piwigo\Config\Config;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Plugins\LoadedPluginRegistry;

final readonly class PluginService
{
    public function __construct(
        private PluginRepository $repo,
    ) {
    }

    public static function get(): self
    {
        return ServiceLocator::get(self::class);
    }

    /** @return array<array<string,mixed>> */
    public function getDbPlugins(string $state = ''): array
    {
        return $this->repo->findAll($state);
    }

    /** @param array<string,mixed> $plugin */
    public function loadPlugin(array $plugin): void
    {
        $pluginId  = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
        $fileName  = Config::pluginsPath() . $pluginId . '/main.inc.php';
        if (file_exists($fileName)) {
            $this->autoupdatePlugin($plugin);
            LoadedPluginRegistry::register($pluginId, $plugin);
            require_once($fileName);
        }
    }

    /** @param array<string,mixed> $plugin */
    public function autoupdatePlugin(array &$plugin): void
    {
        $pluginId  = is_scalar($plugin['id']) ? (string) $plugin['id'] : '';
        $fh        = fopen(Config::pluginsPath() . $pluginId . '/main.inc.php', 'r');
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
              ServiceLocator::get(StringUtil::class)->safeVersionCompare($pluginVersion, $fsVersion, '<')
        )
        ) {
            $oldVersion = $pluginVersion;
            $newVersion = $fsVersion;

            $plugin['version'] = $fsVersion;

            $maintainFile = Config::pluginsPath() . $pluginId . '/maintain.class.php';

            if (file_exists($maintainFile)) {
                require_once($maintainFile);

                $classname = str_replace('-', '_', $pluginId) . '_maintain';

                if (class_exists($classname) && is_a($classname, PluginMaintain::class, true)) {
                    $pluginMaintain = new $classname($pluginId); // @phpstan-ignore piwigo.noDynamicNew
                    $errors         = &PageState::current()->errors;
                    $pluginMaintain->update($oldVersion, $fsVersion, $errors);
                }
            }

            if ($newVersion != $oldVersion) {
                $this->repo->updateVersion($pluginId, $fsVersion);
                ServiceLocator::get(Util::class)->pwgActivity('system', ActivitySystem::Plugin, 'autoupdate', ['plugin_id' => $pluginId, 'from_version' => $oldVersion, 'to_version' => $newVersion]);
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
            EventDispatcher::notify('plugins_loaded');
        }
    }
}
