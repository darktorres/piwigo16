<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Event;

/**
 * Typed marker event for the legacy `plugins_loaded` notification. No
 * payload, no handler registered anywhere today.
 */
final readonly class PluginsLoaded {}
