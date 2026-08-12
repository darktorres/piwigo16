<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Core\TemplatePageContext;

/**
 * The full, fixed 'stats.latte' template variable set assigned by
 * {@see \Piwigo\Admin\StatsPageRenderer::render()} -- 11 named values,
 * fully enumerated, no dynamic/optional keys.
 */
final readonly class StatsPageContext implements TemplatePageContext
{
    /**
     * @param float[]|int[] $compareYears
     * @param array{month?: list<array<int|string, float|int>>, avg: ?float} $monthStats
     * @param float[]|int[] $lastHours
     * @param float[]|int[] $lastDays
     * @param float[]|int[] $lastMonths
     * @param float[]|int[] $lastYears
     */
    public function __construct(
        public string $helpUrl,
        public string $formAction,
        public array $compareYears,
        public array $monthStats,
        public array $lastHours,
        public array $lastDays,
        public array $lastMonths,
        public array $lastYears,
        public LangCode $langCode,
        public string $monthLabels,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_HELP' => $this->helpUrl,
            'F_ACTION' => $this->formAction,
            'compareYears' => $this->compareYears,
            'monthStats' => $this->monthStats,
            'lastHours' => $this->lastHours,
            'lastDays' => $this->lastDays,
            'lastMonths' => $this->lastMonths,
            'lastYears' => $this->lastYears,
            'langCode' => $this->langCode->value,
            'month_labels' => $this->monthLabels,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
