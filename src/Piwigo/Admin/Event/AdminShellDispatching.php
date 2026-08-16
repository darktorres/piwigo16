<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed marker event for the legacy `loc_begin_admin` notification. No
 * payload, no handler registered anywhere today. Renamed and co-located here from `Piwigo\Event\Location\LocBeginAdmin` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class AdminShellDispatching {}
