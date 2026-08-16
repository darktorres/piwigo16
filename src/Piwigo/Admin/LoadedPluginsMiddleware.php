<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\PluginConfig\CurrentPluginRegistry;
use Piwigo\PluginConfig\PluginManifest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Repopulates `LoadedPlugins` from the plugin registry `Http\Middleware\
 * PluginBootstrapMiddleware` already booted and published to
 * `CurrentPluginRegistry` (workstream C3 Phase 1) -- verbatim-ported from
 * `Bootstrap\RequestBootstrap::connect()`'s own identical block.
 *
 * Lives in `Piwigo\Admin\` (L4Integration), a separate real middleware
 * from `PluginBootstrapMiddleware` (L3Presentation, `Http\Middleware\*`)
 * specifically because `LoadedPlugins` itself is L4Integration --
 * `connect()`'s own original comment already explained this exact split:
 * "`PluginRegistry` (P27.3, `PluginConfig\`, L3Presentation) can't take
 * `Admin\LoadedPlugins` (`Admin\`, L4Integration) as a constructor param
 * itself without an L3->L4 deptrac violation, so this glue stays here."
 * That reasoning still holds once the glue becomes real middleware instead
 * of a procedural bootstrap step -- it just means the glue is its own
 * middleware now, positioned immediately after `PluginBootstrapMiddleware`
 * in `RequestPipeline::DEFAULT_MIDDLEWARE` rather than folded into it.
 */
final readonly class LoadedPluginsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private CurrentPluginRegistry $currentPluginRegistry,
        private LoadedPlugins $loadedPlugins,
    ) {}

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $pluginRegistry = $this->currentPluginRegistry->get();

        // Guarded on the same enablePlugins flag PluginRegistry::
        // bootActive() itself checks -- getActiveIds() is a plain DB query
        // with no such guard of its own, so without this check here too, a
        // disabled-plugins request would still report every active plugin
        // as "loaded" despite bootActive() never having constructed or
        // booted a single instance.
        $this->loadedPlugins->set([]);
        if ($this->currentConfig->enablePlugins) {
            foreach ($pluginRegistry->getActiveIds() as $activePluginId) {
                $manifest = $pluginRegistry->getManifest($activePluginId);
                if (! $manifest instanceof PluginManifest) {
                    continue;
                }
                $this->loadedPlugins->add($activePluginId, [
                    'id' => $activePluginId,
                    'state' => 'active',
                    'version' => $manifest->version,
                ]);
            }
        }

        return $handler->handle($request);
    }
}
