<?php

declare(strict_types=1);

namespace Piwigo\Event\Search;

/**
 * Typed event for the legacy `qsearch_get_scopes` filter. No handler is
 * registered for it anywhere today. `$scopes` stays loosely `array<mixed>`
 * (not the real `array<QSearchScope>` shape `getQuickSearchResultsNoCache()`
 * itself builds): the one real consumer already defensively filters each
 * element via `instanceof QSearchScope`, a real plugin can hand back a
 * malformed shape, and a precise element type would make PHPStan treat
 * that filter as dead code (same reasoning as GetAdminPluginMenuLinks/
 * GetBatchManagerPrefilters from the Admin/Integrity/Upload batch). Also
 * avoids needing the `Piwigo\Search\Event\` namespace override every
 * other event in this batch needs -- a loose `array<mixed>` carries no
 * first-party type for deptrac to see.
 */
final readonly class QsearchGetScopes
{
    /**
     * @param array<mixed> $scopes
     */
    public function __construct(
        public array $scopes,
    ) {}
}
