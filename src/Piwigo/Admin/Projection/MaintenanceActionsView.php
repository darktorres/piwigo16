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
final readonly class MaintenanceActionsView implements View, HasPageAssets, ExposesPageData
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

    /**
     * `maintenance_actions.latte`'s own unconditional `{do combineScript(...)}`x4/
     * `{do combineCss(...)}`x3 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order: 10 is required, see issue 1080.
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/maintenance_actions.css', id: 'maintenance_actions'),
            AssetContribution::script('maintenance_actions', 'themes/admin/default/js/maintenance_actions.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery.confirm', 'page-data']),
            AssetContribution::script('ajax', 'themes/admin/default/js/maintenance.ts', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'u_maint_lock_gallery' => $this->maintLockGallery,
            'pwg_token' => $this->pwgToken,
        ];
    }

    /**
     * `maintenance_actions.latte`'s own unconditional `{do exposeString(...)}`x13
     * (docs/PLAN.md's P42-B) -- `'Yes, I am sure'`/`'No, I have changed
     * my mind'` are dropped outright, not ported here: 2 of the 3
     * theme-base confirm-dialog strings `ThemeBaseAssets` already
     * registers unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'right now',
            '%s MB',
            'A locked gallery is only visible to administrators',
            'Are you sure you want to lock the gallery?',
            'Are you sure you want to unlock the gallery?',
            'Yes, I want to lock the gallery',
            'Keep it unlocked',
            'Purge history detail',
            'Purge history summary',
            'Purge search history',
            'Are you sure you want to delete all sizes?',
        ];
    }
}
