<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Event;

/**
 * Typed marker event for the legacy `loc_end_intro` notification. No
 * payload, no handler registered anywhere today. Renamed and co-located here from `Piwigo\Event\Location\LocEndIntro` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class IntroPageRendered {}
