<?php

declare(strict_types=1);

namespace Piwigo\Lang\Event;

/**
 * Typed marker event for the legacy `loading_lang` notification. No
 * payload, no handler registered anywhere today. Co-located here from `Piwigo\Event\Lifecycle\LoadingLang` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final readonly class LoadingLang {}
