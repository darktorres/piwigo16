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
 * `configuration_sizes.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'sizes'` case, merged with `processSizes()`'s own POST-handler result
 * (see that method's own return type) and the whole-page shared fields
 * every `configuration_*.latte` tab needs -- see {@see
 * ConfigurationMainView}. `$sizes` and `$derivatives` are non-nullable:
 * the template reads both unguarded, and both construction sites really do
 * supply them -- the fresh render computes them, and the
 * validation-failure redisplay takes them from `processSizes()`'s own
 * result, whose `$sizesLoadedInTpl` flag is set in the same branch that
 * populates them. `$isGd` is null on that second path (P58-A's §3/§11).
 * `$ferrors` is only ever non-null on that same validation-failure
 * branch. No `$customDerivatives` field -- the
 * template's own body never references it.
 */
#[Template('configuration_sizes.latte')]
final readonly class ConfigurationSizesView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, DerivativeSizeRow> $derivatives
     * @param array<string, mixed>|null $ferrors
     */
    public function __construct(
        public ?bool $isGd,
        public ConfigurationSizesTabData $sizes,
        public array $derivatives,
        public string|int|null $resizeQuality,
        public ?array $ferrors,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}

    /**
     * `configuration_sizes.latte`'s own unconditional
     * `{do combineScript(...)}`x2/`{do combineCss(...)}`x2 (docs/PLAN.md's
     * P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::script('configuration_sizes', 'themes/admin/default/js/configuration_sizes.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.confirm']),
            AssetContribution::css('themes/admin/default/css/pages/configuration_sizes.css', id: 'configuration_sizes'),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * `configuration_sizes.latte`'s own unconditional `{do exposeString(...)}`x7
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to restore to default settings?',
            'Maximum width',
            'Width',
            'Maximum height',
            'Height',
        ];
    }
}
