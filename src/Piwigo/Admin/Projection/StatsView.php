<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `stats.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\StatsPageRenderer::render()}. No `$formAction` field --
 * the template's own body never references it (a real, standalone gap
 * distinct from `U_HELP`, which the template also never references but
 * which is genuinely ambient: `admin.latte`'s own shell reads it
 * separately from this page's own body, via `AdminContentPageContext`).
 */
#[Template('stats.latte')]
final readonly class StatsView implements View
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
        public array $compareYears,
        public array $monthStats,
        public array $lastHours,
        public array $lastDays,
        public array $lastMonths,
        public array $lastYears,
        public string $langCode,
        public string $monthLabels,
    ) {}
}
