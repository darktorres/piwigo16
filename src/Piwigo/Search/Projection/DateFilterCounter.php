<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * One preset row of the search sidebar's date filter -- "last 24 hours",
 * "last 7 days" and so on -- with the number of photos that fall inside
 * that window (P58-A).
 *
 * `$counter` is a plain `int`. It was `mixed`, because
 * {@see \Piwigo\Search\SearchFilterRenderer::renderDateFilter()} reads its
 * pre-counts back out of the persistent cache pool, which returns
 * untyped arrays. The narrowing happens at that read now, so the count is
 * an int by the time it reaches this class rather than being carried as
 * `mixed` all the way into the template, where it is compared against 0
 * twice per row.
 */
final readonly class DateFilterCounter
{
    public function __construct(
        public string $label,
        public int $counter,
    ) {}
}
