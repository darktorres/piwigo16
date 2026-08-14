<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.plugins.getList` -- returns the list of all plugins.
 */
final readonly class PluginsGetListHandler implements WsAction
{
    public function __construct(
        private Lang $lang,
        private UrlServiceInterface $urlService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return list<array{id: string, name: string, version: string, state: string, description: string}>
     */
    public function __invoke(array $params, Server $server): array
    {
        $urlService = $this->urlService;
        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape (by design --
        // see that method's own docblock), but every real entry for
        // ExtensionType::Plugin is actually scanPlugin()'s own precise shape.
        /** @var array<string, array{name: string, version: string, uri: string,
         *   description: string, author: string, hasSettings: bool,
         *   'author uri'?: string, extension?: string}> $fs_plugins
         */
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
        uasort($fs_plugins, $this->htmlRenderer->nameCompare(...));
        $db_plugins_by_id = new ExtensionRepository($this->entityManager)
            ->findAll(ExtensionType::Plugin);
        $plugin_list = [];

        foreach ($fs_plugins as $plugin_id => $fs_plugin) {
            if (isset($db_plugins_by_id[$plugin_id]) && is_string($db_plugins_by_id[$plugin_id]['state'])) {
                $state = $db_plugins_by_id[$plugin_id]['state'];
            } else {
                $state = 'uninstalled';
            }

            $plugin_list[] = [
                'id' => $plugin_id,
                'name' => $fs_plugin['name'],
                'version' => $fs_plugin['version'],
                'state' => $state,
                'description' => $fs_plugin['description'],
            ];
        }

        return $plugin_list;
    }
}
