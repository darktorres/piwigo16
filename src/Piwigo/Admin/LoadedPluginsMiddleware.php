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
 * `CurrentPluginRegistry`.
 *
 * Lives in `Piwigo\Admin\` (L4Integration), a separate real middleware
 * from `PluginBootstrapMiddleware` (L3Presentation, `Http\Middleware\*`)
 * specifically because `LoadedPlugins` itself is L4Integration:
 * `PluginConfig\PluginRegistry` (L3Presentation) can't take `Admin\
 * LoadedPlugins` (`Admin\`, L4Integration) as a constructor param without
 * an L3->L4 deptrac violation, so this glue stays as its own middleware,
 * positioned immediately after `PluginBootstrapMiddleware` in
 * `RequestPipeline::DEFAULT_MIDDLEWARE`.
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
