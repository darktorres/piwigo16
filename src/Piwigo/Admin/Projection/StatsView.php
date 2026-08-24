<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
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
final readonly class StatsView implements View, HasPageAssets, ExposesPageData
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

    /**
     * `stats.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}`x1 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('chart.js', 'https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/chart.js@2.9.3/dist/Chart.min.css'),
            AssetContribution::script('moment-with-locales.js', 'https://cdn.jsdelivr.net/npm/moment@2.26.0/min/moment-with-locales.min.js'),
            AssetContribution::script('stats', 'themes/admin/default/js/stats.js', loadMode: LoadMode::Footer, dependsOn: ['chart.js', 'moment-with-locales.js', 'page-data']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'month_labels' => $this->monthLabels,
            'lang_code' => $this->langCode,
        ];
    }

    /**
     * `stats.latte`'s own unconditional `{do exposeString(...)}`x2
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Page Visited',
            'Average last 12 months',
        ];
    }
}
