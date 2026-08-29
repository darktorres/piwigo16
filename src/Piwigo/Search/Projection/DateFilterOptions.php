<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * Both halves of one date-filter panel: the preset rows ("last 7 days")
 * and the year/month/day tree behind "Custom dates" (P58-A).
 *
 * They are one object because
 * {@see \Piwigo\Search\SearchFilterRenderer::render()} assigns them
 * together from a single {@see
 * \Piwigo\Search\SearchFilterRenderer::renderDateFilter()} call, or
 * assigns neither. As four separate nullable fields on `SearchFilterData`
 * that correlation was invisible, and `search_filters.inc.latte` had to
 * ask for it with `{if isset($datePosted) or isset($listDatePosted)}` --
 * an `or`, which narrows neither operand, so both `{foreach}`es inside
 * the block still ran on a possibly-null value. One nullable object per
 * panel makes the guard a single `!== null` that narrows what follows it.
 */
final readonly class DateFilterOptions
{
    /**
     * @param array<string, DateFilterCounter> $counters keyed by threshold
     *   id ('24h', '7d', ...), in display order
     * @param array<array-key, DateFilterYear> $listOfDates keyed by year
     */
    public function __construct(
        public array $counters,
        public array $listOfDates,
    ) {}
}
