<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Fires once per request, after PluginRegistry::bootActive() has booted
 * every active plugin and registered their subscribers. Core/plugin code
 * that needs to react after the plugin graph is fully wired (and is itself
 * NOT registered through that graph) can subscribe here.
 *
 * Dispatched from: src/Piwigo/Plugin/PluginRegistry.php (bootActive)
 */
final readonly class PluginsLoaded
{
}
