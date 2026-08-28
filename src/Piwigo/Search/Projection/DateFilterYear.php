<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One year in the search sidebar's date-filter tree -- the year/month/day
 * accordion `search_filters.inc.latte` renders for the "posted" and
 * "created" panels alike, keyed by year (P58-A).
 *
 * {@see \Piwigo\Search\SearchFilterRenderer::renderDateFilter()} counts
 * these into plain nested arrays and only freezes them into this shape at
 * the end. That is deliberate: the counted arrays go through the persistent
 * cache pool, and objects must not -- a serialized class name is a migration
 * hazard the moment the class moves namespace.
 *
 * `$label` stays a preformatted string rather than moving to the template,
 * unlike the row VOs elsewhere in this campaign. Every level's label needs
 * translator state -- `t('year %d', …)` here, and the month's needs the
 * localized month-name table -- and the template layer has no accessor for
 * the latter. Splitting the three so that only some format in the template
 * would be worse than either end of the choice.
 */
final readonly class DateFilterYear
{
    /**
     * @param array<array-key, DateFilterMonth> $months keyed by `Y-m`
     */
    public function __construct(
        public string $label,
        public int $count,
        public array $months,
    ) {}
}
