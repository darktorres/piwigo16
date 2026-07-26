<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Controller\Admin\Request\PluginSectionRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugin.php's own body (page slug "plugin"), folded
 * directly into this controller (P23 sub-batch 6i-3) -- dynamic inclusion
 * of a whitelisted file from within an already-active plugin's own
 * directory (e.g. that plugin's settings page). Doesn't touch the
 * plugins/themes/languages god-classes at all, only $pwg_loaded_plugins (a
 * real, already-established global from Piwigo\Admin\PluginLoader's
 * plugin-loading bootstrap chain, unrelated to this batch's real scope --
 * same usage already exists in BatchManagerUnitPageRenderer). No other real
 * caller of admin/plugin.php exists (confirmed via grep) -- admin.php's own
 * routing already gates this page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the shell's
 * own (redundant) copy of that check is dropped here, same precedent as
 * every prior sub-batch's shell fold.
 *
 * P27/SEC-40: $_GET['section'] parsing/validation extracted into
 * Request\PluginSectionRequest -- see that class's own docblock for the
 * real denial-of-service bug found and fixed during the original port
 * (an unreindexed unset() during empty-segment filtering).
 */
final class PluginSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $pluginSection = PluginSectionRequest::fromGlobals();

        if (! isset(\Piwigo\Admin\LoadedPlugins::get()[$pluginSection->pluginId])) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('Invalid URL - plugin ' . $pluginSection->pluginId . ' not active');
        }

        $filename = \Piwigo\Admin\PluginLoader::pluginsPath() . implode('/', $pluginSection->sections);
        if (is_file($filename)) {
            include_once $filename;
        } else {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('Missing file ' . htmlentities($filename));
        }
    }
}
