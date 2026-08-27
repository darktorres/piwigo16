<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Admin\Projection\ColorboxView;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_main.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'main'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs (`$fAction`/`$saveSuccess`/
 * `$isWebmaster`/`$csrfToken` -- see the sibling `Configuration*View`
 * classes for this same shared set).
 */
#[Template('configuration_main.latte')]
final readonly class ConfigurationMainView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, mixed> $main
     * @param array<int, string> $groupOptions
     */
    public function __construct(
        public array $main,
        public array $groupOptions,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            ...new ColorboxView()
                ->pageAssets(),
            AssetContribution::script('configuration_main', 'themes/admin/default/js/configuration_main.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery', 'jquery.tipTip', 'jquery.colorbox']),
            AssetContribution::css('themes/admin/default/css/pages/configuration_main.css', id: 'configuration_main'),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'order_by_options_count' => is_array($this->main['order_by_options'] ?? null) ? count($this->main['order_by_options']) : 0,
        ];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
