<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_search.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'search'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs -- see {@see ConfigurationMainView}.
 */
#[Template('configuration_search.latte')]
final readonly class ConfigurationSearchView implements View, HasPageAssets, ExposesPageData
{
    public function __construct(
        public ConfigurationSearchTabData $search,
        public bool $showFilterRatings,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}

    /**
     * `configuration_search.latte`'s own unconditional
     * `{do combineScript(...)}`x2/`{do combineCss(...)}` (docs/PLAN.md's
     * P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('configuration_search', 'themes/admin/default/js/configuration_search.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
        ];
    }

    /**
     * `configuration_search.latte`'s own unconditional
     * `{do exposeData('filters_names', $search['filters_names'])}`
     * (docs/PLAN.md's P42-B). The key name is the one
     * `configuration_search.ts` reads through `pwg_getPageData()` and
     * does not follow the property rename.
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'filters_names' => $this->search->filtersNames,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
