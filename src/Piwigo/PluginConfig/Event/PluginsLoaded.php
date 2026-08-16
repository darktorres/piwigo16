<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Event;

/**
 * Typed marker event for the legacy `plugins_loaded` notification. No
 * payload, no handler registered anywhere today. Co-located here from `Piwigo\Event\Lifecycle\PluginsLoaded` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class PluginsLoaded {}
