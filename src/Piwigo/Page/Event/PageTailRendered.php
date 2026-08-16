<?php

declare(strict_types=1);

namespace Piwigo\Page\Event;

/**
 * Typed marker event for the legacy `loc_end_page_tail` notification.
 * No payload, no handler registered anywhere today. Renamed and co-located here from `Piwigo\Event\Location\LocEndPageTail` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class PageTailRendered {}
