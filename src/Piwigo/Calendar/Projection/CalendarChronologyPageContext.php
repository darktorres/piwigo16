<?php

declare(strict_types=1);

namespace Piwigo\Calendar\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Calendar\CalendarRenderer::render()}'s own
 * category-calling branch. `$chronologyNavigationBars` is
 * `month_calendar.tpl`'s own `chronology_navigation_bars` var, built by
 * {@see \Piwigo\Calendar\CalendarBase::build_nav_bar()} and build_next_prev()
 * over the course of `generate_category_content()` and read back once
 * that call returns via
 * {@see \Piwigo\Calendar\CalendarBase::getChronologyNavigationBars()} --
 * always included (not optional) since the .tpl checks it with
 * `{if !empty(...)}`, not `isset()`. `$chronologyViews` is genuinely
 * optional -- the original code's own nested style/view loop only
 * appends when at least one style/view combination passes its own
 * `view_calendar` gate, omitted here (not present as a null value) to
 * match `index.tpl`'s own `{if isset($chronology_views)}` guard exactly.
 */
final readonly class CalendarChronologyPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $chronologyNavigationBars
     * @param list<array{VALUE: string, CONTENT: string, SELECTED: bool}>|null $chronologyViews
     */
    public function __construct(
        public string $fileChronologyView,
        public string $chronologyTitle,
        public array $chronologyNavigationBars,
        public ?array $chronologyViews,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'FILE_CHRONOLOGY_VIEW' => $this->fileChronologyView,
            'chronology' => [
                'TITLE' => $this->chronologyTitle,
            ],
            'chronology_navigation_bars' => $this->chronologyNavigationBars,
        ];

        if ($this->chronologyViews !== null) {
            $result['chronology_views'] = $this->chronologyViews;
        }

        return $result;
    }
}
