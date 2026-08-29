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
 * `configuration_watermark.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'watermark'` case, merged with `processWatermark()`'s own POST-handler
 * result (see that method's own return type) and the whole-page shared
 * fields every `configuration_*.latte` tab needs -- see {@see
 * ConfigurationMainView}. `$watermark` is always present by render time
 * (either `processWatermark()`'s own validation-failure echo, or a fresh
 * default computed here) -- the template's own body reads its inner keys
 * (`$watermark['file']`/`['position']`/etc.) unguarded. `$ferrors` is
 * only ever non-null on `processWatermark()`'s own validation-failure
 * branch.
 */
#[Template('configuration_watermark.latte')]
final readonly class ConfigurationWatermarkView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $watermarkFiles
     * @param array<string, string> $watermark
     */
    public function __construct(
        public array $watermarkFiles,
        public array $watermark,
        public ?WatermarkFormErrors $ferrors,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
        public string $rootUrl,
    ) {}

    /**
     * `configuration_watermark.latte`'s own unconditional
     * `{do combineScript(...)}`x2/`{do combineCss(...)}` (docs/PLAN.md's
     * P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('configuration_watermark', 'themes/admin/default/js/configuration_watermark.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/configuration_watermark.css', id: 'configuration_watermark'),
        ];
    }

    /**
     * `configuration_watermark.latte`'s own unconditional
     * `{do exposeData('root_url', $ROOT_URL)}` (docs/PLAN.md's P42-B) --
     * `$ROOT_URL` itself is a true cross-cutting ambient, not a View
     * property before this migration; the controller now obtains the
     * same value via `UrlServiceInterface::getRootUrl()`, the same
     * service `Template` itself uses.
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [
            'root_url' => $this->rootUrl,
        ];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
