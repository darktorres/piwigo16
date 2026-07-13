<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugin.php (page slug "plugin") -- dynamic inclusion of a
 * whitelisted file from within an already-active plugin's own directory
 * (e.g. that plugin's settings page). Pure delegate: doesn't touch the
 * plugins/themes/languages god-classes at all, only $pwg_loaded_plugins
 * (P20's PluginConfig\EventDispatcher concern), so nothing to migrate here.
 */
final class PluginSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/plugin.php';
    }
}
