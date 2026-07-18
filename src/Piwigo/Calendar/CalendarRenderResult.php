<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

/**
 * Return value of CalendarRenderer::render() -- Legacy Coupling Retirement
 * Track A batch A5.2e: replaces the `global $page['items']`/`['comment']`/
 * `['chronology_date']`/`['chronology_style']`/`['chronology_view']` writes
 * the legacy body used to make directly. Single real caller
 * (SectionPopulator::populate(), an in-flight collaborator that calls this
 * before its own SectionContext exists -- see that class's own docblock),
 * which merges these fields back onto the local scratch state it's building
 * up into a SectionContext.
 */
final readonly class CalendarRenderResult
{
    /**
     * @param list<int|string> $items
     * @param list<int|string> $chronologyDate
     */
    public function __construct(
        public array $items,
        public string $comment,
        public array $chronologyDate,
        public ?string $chronologyStyle,
        public ?string $chronologyView,
    ) {}
}
