<?php

declare(strict_types=1);

namespace Piwigo\Admin\Event;

/**
 * Typed marker event for the legacy `loc_begin_element_set_global`
 * notification. No payload, no handler registered anywhere today. Renamed and co-located here from `Piwigo\Event\Location\LocBeginElementSetGlobal` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class BatchManagerGlobalRendering {}
