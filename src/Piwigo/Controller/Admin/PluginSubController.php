<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\LoadedPlugins;
use Piwigo\Controller\Admin\Request\PluginSectionRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\PluginConfig\SettingsPageInterface;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugin.php's own body (page slug "plugin"), folded
 * directly into this controller -- dispatches to the requested plugin's
 * own `PluginConfig\SettingsPageInterface::handleSettingsRequest()`
 * (P27.15), the real, already-`boot()`ed instance
 * `PluginConfig\CurrentPluginRegistry` holds (see that class's own
 * docblock for why this reads through it rather than a freshly
 * container-autowired `PluginRegistry`). Doesn't touch the
 * plugins/themes/languages god-classes at all, only $pwg_loaded_plugins (a
 * real, already-established global from Piwigo\Admin\PluginLoader's
 * plugin-loading bootstrap chain -- same usage already exists in
 * BatchManagerUnitPageRenderer). No other real
 * caller of admin/plugin.php exists -- admin.php's own
 * routing already gates this page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the shell's
 * own (redundant) copy of that check is dropped here.
 *
 * $_GET['section'] parsing/validation is extracted into
 * Request\PluginSectionRequest -- see that class's own docblock.
 */
final readonly class PluginSubController implements AdminSubControllerInterface
{
    public function __construct(
        private LoadedPlugins $loadedPlugins,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private CurrentPluginRegistry $currentPluginRegistry,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $pluginSection = PluginSectionRequest::fromGlobals($this->inputValidator);

        if (! isset($this->loadedPlugins->get()[$pluginSection->pluginId])) {
            $this->htmlRenderer
                ->fatalError('Invalid URL - plugin ' . $pluginSection->pluginId . ' not active');
        }

        $instance = $this->currentPluginRegistry->get()
            ->getBootedInstance($pluginSection->pluginId);
        if (! $instance instanceof SettingsPageInterface) {
            $this->htmlRenderer
                ->fatalError('Plugin ' . $pluginSection->pluginId . ' has no settings page');
        }

        $instance->handleSettingsRequest($request);
    }
}
