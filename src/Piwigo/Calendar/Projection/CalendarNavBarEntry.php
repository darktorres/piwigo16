<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * {@see \Piwigo\Calendar\CalendarBase::getNavBarFromItems()}'s own row --
 * `$url`/`$nbImages` are independently optional: a `-1` sentinel item
 * (an "empty" label shown with `show_empty`) has neither; a real item
 * always has `$url` but only carries `$nbImages` when it's `> 0`; the
 * trailing "All" link has `$url` but never `$nbImages`.
 */
final readonly class CalendarNavBarEntry
{
    public function __construct(
        public int|string $label,
        public ?string $url,
        public ?int $nbImages,
    ) {}
}
