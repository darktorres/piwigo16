<?php

declare(strict_types=1);

namespace Piwigo\Search\Event;

/**
 * Typed event for the legacy `qsearch_pre` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the query string. Co-located here from `Piwigo\Event\Search\QsearchPre` (P32 Stage A5 -- see `docs/events-legacy-map.md`).
 */
final class QsearchPre
{
    public function __construct(
        public string $q,
    ) {}
}
