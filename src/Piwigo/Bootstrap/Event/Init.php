<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap\Event;

/**
 * Typed marker event for the legacy `init` notification. No payload, no
 * handler registered anywhere today. Co-located here from `Piwigo\Event\Lifecycle\Init` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class Init {}
