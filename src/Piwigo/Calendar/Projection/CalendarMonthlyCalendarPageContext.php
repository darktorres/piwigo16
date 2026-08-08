<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'chronology_calendar' template variable assigned by
 * {@see \Piwigo\Calendar\CalendarMonthly::generate_category_content()}
 * -- the same single key, filled by whichever of
 * build_global_calendar()/build_year_calendar()/build_month_calendar()
 * actually ran (mutually exclusive branches).
 */
final readonly class CalendarMonthlyCalendarPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, mixed> $tplVar
     */
    public function __construct(
        public array $tplVar,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'chronology_calendar' => $this->tplVar,
        ];
    }
}
