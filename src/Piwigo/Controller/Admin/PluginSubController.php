<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Admin\PluginLoader;
use Piwigo\Controller\Admin\Request\PluginSectionRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Paths;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugin.php's own body (page slug "plugin"), folded
 * directly into this controller -- dynamic inclusion
 * of a whitelisted file from within an already-active plugin's own
 * directory (e.g. that plugin's settings page). Doesn't touch the
 * plugins/themes/languages god-classes at all, only $pwg_loaded_plugins (a
 * real, already-established global from Piwigo\Admin\PluginLoader's
 * plugin-loading bootstrap chain -- same usage already exists in
 * BatchManagerUnitPageRenderer). No other real
 * caller of admin/plugin.php exists (confirmed via grep) -- admin.php's own
 * routing already gates this page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the shell's
 * own (redundant) copy of that check is dropped here.
 *
 * $_GET['section'] parsing/validation is extracted into
 * Request\PluginSectionRequest -- see that class's own docblock for the
 * real denial-of-service bug found and fixed
 * (an unreindexed unset() during empty-segment filtering).
 */
final class PluginSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly LoadedPlugins $loadedPlugins,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $pluginSection = PluginSectionRequest::fromGlobals($this->inputValidator);

        if (! isset($this->loadedPlugins->get()[$pluginSection->pluginId])) {
            $this->htmlRenderer
                ->fatalError('Invalid URL - plugin ' . $pluginSection->pluginId . ' not active');
        }

        $filename = PluginLoader::pluginsPath($this->paths) . implode('/', $pluginSection->sections);
        if (is_file($filename)) {
            include_once $filename;
        } else {
            $this->htmlRenderer
                ->fatalError('Missing file ' . htmlentities($filename));
        }
    }
}
