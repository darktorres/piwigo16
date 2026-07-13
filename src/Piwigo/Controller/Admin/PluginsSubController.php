<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugins.php (page slug "plugins") -- a tab dispatcher
 * (installed/new/update) that stays a pure delegate: its own tab-dispatch
 * include shape is unchanged, only the 3 leaf files it includes
 * (plugins_installed.php/plugins_new.php, plus the shared updates_ext.php
 * for the "update" tab) were migrated off the plugins.class.php god-class
 * onto PemCatalog/ExtensionScanner/ExtensionLifecycle/ExtensionRepository
 * (this batch's real scope).
 */
final class PluginsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/plugins.php';
    }
}
