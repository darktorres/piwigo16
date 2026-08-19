<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Config\CacheSizesSnapshot;
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
final readonly class MaintenanceEnvView implements View
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
}
