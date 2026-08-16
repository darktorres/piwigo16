<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for the legacy `qsearch_pre` filter. No handler is
 * registered for it anywhere today. No context -- every real call site
 * passes only the query string.
 */
final class QsearchPre
{
    public function __construct(
        public string $q,
    ) {}
}
