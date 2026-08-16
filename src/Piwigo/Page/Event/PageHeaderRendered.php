<?php

declare(strict_types=1);

namespace Piwigo\Page\Event;

/**
 * Typed marker event for the legacy `loc_after_page_header`
 * notification. No payload, no handler registered anywhere today. Renamed and co-located here from `Piwigo\Event\Lifecycle\LocAfterPageHeader` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class PageHeaderRendered {}
