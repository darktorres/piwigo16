<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

/**
 * `{templateType}` target for `month_calendar.latte`. Never rendered via
 * `Renderer::render()` -- `Piwigo\Calendar\CalendarRenderer` only ever
 * passes the filename as a string value
 * ({@see CalendarChronologyPageContext::$fileChronologyView}), which
 * `index.latte`'s own body turns into a bare `{include $FILE_CHRONOLOGY_VIEW}`
 * -- full parent-scope inheritance, not the isolated `block`/`id`-only
 * scope `menubar.latte`'s own sub-block includes use. Contract-only
 * conversion, same shape as {@see \Piwigo\Menu\Projection\MenubarBlockView}
 * (deliberately not implementing `Piwigo\Core\View`, carrying no
 * `#[Template]` attribute). Property names stay snake_case, matching
 * the real ambient `Template::$vars` keys verbatim (`chronology_calendar`
 * from {@see CalendarMonthlyCalendarPageContext}, `chronology_navigation_bars`
 * from {@see CalendarChronologyPageContext}) -- unlike a real View's
 * `get_object_vars()` merge, inherited-scope names can't be renamed
 * here without touching the classes that assign them.
 */
final readonly class MonthCalendarView
{
    /**
     * @param array<array-key, mixed> $chronology_calendar
     * @param list<array<string, mixed>> $chronology_navigation_bars
     */
    public function __construct(
        public array $chronology_calendar,
        public array $chronology_navigation_bars,
    ) {}
}
