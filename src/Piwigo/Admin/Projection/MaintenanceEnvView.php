<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `maintenance_env.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MaintenanceEnvPageRenderer::render()}. No
 * `$maintCategories`/.../`$maintDerivatives`, `$purgeDerivatives`,
 * `$maintUnlockGallery`/`$maintLockGallery`, or `$advancedFeatures`
 * fields here -- confirmed dead in `maintenance_env.latte`'s own real
 * body: every maintenance-action link and the derivatives purge UI
 * live in the "actions" tab's own template instead (see {@see
 * MaintenanceActionsView}), not this one.
 */
#[Template('maintenance_env.latte')]
final readonly class MaintenanceEnvView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<string> $activePluginNames
     */
    public function __construct(
        public string $phpwgUrl,
        public string $pwgVersion,
        public string $checkUpgradeUrl,
        public ?string $installedOn,
        public ?string $installedSince,
        public string $os,
        public string $containerInfo,
        public string $phpinfoUrl,
        public string $phpVersion,
        public string $phpCurrentTimestamp,
        public string $dbEngine,
        public string $dbVersion,
        public ?string $dbCurrentDate,
        public ?string $graphicsLibrary,
        public ?CacheSizesSnapshot $cacheSizes,
        public ?string $timeElapsedSinceLastCalc,
        public array $activePluginNames,
    ) {}

    /**
     * `maintenance_env.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do combineCss(...)}`x2 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('ajax', 'themes/admin/default/js/maintenance.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/maintenance_env.css', id: 'maintenance_env'),
        ];
    }

    /**
     * `maintenance_env.latte`'s own unconditional `{do exposeString(...)}`x2
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [
            'right now',
            '%s MB',
        ];
    }
}
