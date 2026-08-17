<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;

/**
 * The filesystem-scan + DB-state merge behind the plugin list -- shared by
 * `Admin\MaintenanceEnvPageRenderer` (server-side render) and
 * `Controller\Api\Extensions\PluginListController` (`GET /api/v1/plugins`).
 */
final readonly class PluginListBuilder
{
    public function __construct(
        private UrlServiceInterface $urlService,
        private Lang $lang,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<array{id: string, name: string, version: string, state: string, description: string}>
     */
    public function build(): array
    {
        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape (by design --
        // see that method's own docblock), but every real entry for
        // ExtensionType::Plugin is actually scanPlugin()'s own precise shape.
        /** @var array<string, array{name: string, version: string, uri: string,
         *   description: string, author: string, hasSettings: bool,
         *   'author uri'?: string, extension?: string}> $fs_plugins
         */
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);
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
