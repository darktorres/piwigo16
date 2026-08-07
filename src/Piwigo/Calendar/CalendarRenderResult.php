<?php

declare(strict_types=1);

namespace Piwigo\Calendar;

/**
 * Return value of CalendarRenderer::render(). The single caller is
 * SectionPopulator::populate(), which calls this before its own
 * SectionContext exists (see that class's own docblock) and merges these
 * fields onto the local scratch state it's building into a SectionContext.
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
