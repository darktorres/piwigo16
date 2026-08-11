<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Config\CacheSizesSnapshot;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\MaintenanceActionsPageRenderer::render()}.
 * `$graphicsLibrary`, `$maintUnlockGallery`/`$maintLockGallery`, and
 * `$uEmptyLounge`/`$loungeCounter` are genuinely optional -- the
 * original code only ever assigns those template keys under their own
 * runtime condition, omitted here (not present as a null value) to
 * match that exact original behavior. `$maintUnlockGallery`/
 * `$maintLockGallery` are also mutually exclusive (gallery lock state).
 */
final readonly class MaintenanceActionsPageContext implements TemplatePageContext
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
        public string $maintDerivatives,
        public array $purgeDerivatives,
        public string $helpUrl,
        public string $phpwgUrl,
        public string $pwgVersion,
        public string $checkUpgradeUrl,
        public string $os,
        public string $phpVersion,
        public string $dbEngine,
        public string $dbVersion,
        public string $phpinfoUrl,
        public string $phpCurrentTimestamp,
        public ?string $dbCurrentDate,
        public string $pwgToken,
        public ?CacheSizesSnapshot $cacheSizes,
        public ?string $timeElapsedSinceLastCalc,
        public ?string $graphicsLibrary,
        public ?string $maintUnlockGallery,
        public ?string $maintLockGallery,
        public ?string $uEmptyLounge,
        public ?int $loungeCounter,
        public int $isWebmaster,
        public array $advancedFeatures,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'maint_actions' => $this->maintActions,
            'U_MAINT_CATEGORIES' => $this->maintCategories,
            'U_MAINT_IMAGES' => $this->maintImages,
            'U_MAINT_ORPHAN_TAGS' => $this->maintOrphanTags,
            'U_MAINT_USER_CACHE' => $this->maintUserCache,
            'U_MAINT_HISTORY_DETAIL' => $this->maintHistoryDetail,
            'U_MAINT_HISTORY_SUMMARY' => $this->maintHistorySummary,
            'U_MAINT_SESSIONS' => $this->maintSessions,
            'U_MAINT_FEEDS' => $this->maintFeeds,
            'U_MAINT_DATABASE' => $this->maintDatabase,
            'U_MAINT_C13Y' => $this->maintC13y,
            'U_MAINT_SEARCH' => $this->maintSearch,
            'U_MAINT_COMPILED_TEMPLATES' => $this->maintCompiledTemplates,
            'U_MAINT_DERIVATIVES' => $this->maintDerivatives,
            'purge_derivatives' => $this->purgeDerivatives,
            'U_HELP' => $this->helpUrl,
            'APP_URL' => $this->phpwgUrl,
            'APP_VERSION' => $this->pwgVersion,
            'U_CHECK_UPGRADE' => $this->checkUpgradeUrl,
            'OS' => $this->os,
            'PHP_VERSION' => $this->phpVersion,
            'DB_ENGINE' => $this->dbEngine,
            'DB_VERSION' => $this->dbVersion,
            'U_PHPINFO' => $this->phpinfoUrl,
            'PHP_DATATIME' => $this->phpCurrentTimestamp,
            'DB_DATATIME' => $this->dbCurrentDate,
            'pwg_token' => $this->pwgToken,
            'cache_sizes' => $this->cacheSizes,
            'time_elapsed_since_last_calc' => $this->timeElapsedSinceLastCalc,
            'isWebmaster' => $this->isWebmaster,
            'advanced_features' => $this->advancedFeatures,
        ];

        if ($this->graphicsLibrary !== null) {
            $result['GRAPHICS_LIBRARY'] = $this->graphicsLibrary;
        }

        if ($this->maintUnlockGallery !== null) {
            $result['U_MAINT_UNLOCK_GALLERY'] = $this->maintUnlockGallery;
        }

        if ($this->maintLockGallery !== null) {
            $result['U_MAINT_LOCK_GALLERY'] = $this->maintLockGallery;
        }

        if ($this->uEmptyLounge !== null) {
            $result['U_EMPTY_LOUNGE'] = $this->uEmptyLounge;
        }

        if ($this->loungeCounter !== null) {
            $result['LOUNGE_COUNTER'] = $this->loungeCounter;
        }

        return $result;
    }
}
