<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `maintenance_actions.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\MaintenanceActionsPageRenderer::render()}. No
 * `$maintDerivatives`, `$phpwgUrl`/`$pwgVersion`/`$checkUpgradeUrl`,
 * `$os`/`$phpVersion`/`$dbEngine`/`$dbVersion`/`$phpinfoUrl`/
 * `$phpCurrentTimestamp`/`$dbCurrentDate`, or `$graphicsLibrary`
 * fields here -- confirmed dead in `maintenance_actions.latte`'s own
 * real body: server/environment info lives in the "env" tab's own
 * template instead (see {@see MaintenanceEnvView}), and the
 * derivatives purge UI never renders `U_MAINT_DERIVATIVES` itself
 * (only `$purgeDerivatives`'s own per-type values).
 * `$maintUnlockGallery`/`$maintLockGallery` and `$uEmptyLounge`/
 * `$loungeCounter` are each genuinely optional and independently
 * nullable -- null when their own runtime condition doesn't hold.
 * `$maintUnlockGallery`/`$maintLockGallery` are also mutually
 * exclusive (gallery lock state).
 */
#[Template('maintenance_actions.latte')]
final readonly class MaintenanceActionsView implements View
{
    /**
     * @param array<string, array{icon: string, label: string}> $maintActions
     * @param array<string, string> $purgeDerivatives
     * @param array<mixed> $advancedFeatures
     */
    public function __construct(
        public array $maintActions,
        public string $maintCategories,
        public string $maintImages,
        public string $maintOrphanTags,
        public string $maintUserCache,
        public string $maintHistoryDetail,
        public string $maintHistorySummary,
        public string $maintSessions,
        public string $maintFeeds,
        public string $maintDatabase,
        public string $maintC13y,
        public string $maintSearch,
        public string $maintCompiledTemplates,
        public array $purgeDerivatives,
        public string $pwgToken,
        public ?CacheSizesSnapshot $cacheSizes,
        public ?string $timeElapsedSinceLastCalc,
        public ?string $maintUnlockGallery,
        public ?string $maintLockGallery,
        public ?string $uEmptyLounge,
        public ?int $loungeCounter,
        public int $isWebmaster,
        public array $advancedFeatures,
    ) {}
}
