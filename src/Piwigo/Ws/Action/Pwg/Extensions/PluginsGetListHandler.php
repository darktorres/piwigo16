<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Extensions;

use Piwigo\Admin\Plugins;
use Piwigo\Core\Kernel;
use Piwigo\Plugin\PluginState;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.extensions.plugins.getList` — every installed plugin's state + metadata. */
final readonly class PluginsGetListHandler implements WsAction
{
    /**
     * @param  array<mixed> $params
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): array
    {
        $plugins = Kernel::service(Plugins::class);
        $plugins->sortFsPlugins('name');
        $pluginList = [];
        foreach ($plugins->fs_plugins as $pluginId => $fsPlugin) {
            $state        = isset($plugins->db_plugins_by_id[$pluginId]) ? $plugins->db_plugins_by_id[$pluginId]->state : PluginState::Uninstalled;
            $pluginList[] = ['id' => $pluginId, 'name' => $fsPlugin['name'], 'version' => $fsPlugin['version'], 'state' => $state->value, 'description' => $fsPlugin['description']];
        }
        return $pluginList;
    }
}
