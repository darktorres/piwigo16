<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MaintenanceEnvPageContext;

test('toArray flattens every fixed property, and omits the 5 optional keys when null', function (): void {
    $context = new MaintenanceEnvPageContext(
        maintCategories: '/admin.php?page=maintenance&action=categories',
        maintImages: '/admin.php?page=maintenance&action=images',
        maintOrphanTags: '/admin.php?page=maintenance&action=delete_orphan_tags',
        maintUserCache: '/admin.php?page=maintenance&action=user_cache',
        maintHistoryDetail: '/admin.php?page=maintenance&action=history_detail',
        maintHistorySummary: '/admin.php?page=maintenance&action=history_summary',
        maintSessions: '/admin.php?page=maintenance&action=sessions',
        maintFeeds: '/admin.php?page=maintenance&action=feeds',
        maintDatabase: '/admin.php?page=maintenance&action=database',
        maintC13y: '/admin.php?page=maintenance&action=c13y',
        maintSearch: '/admin.php?page=maintenance&action=search',
        maintCompiledTemplates: '/admin.php?page=maintenance&action=compiled-templates',
        maintDerivatives: '/admin.php?page=maintenance&action=derivatives',
        purgeDerivatives: [
            'All' => '/admin.php?page=maintenance&action=derivatives&type=all',
        ],
        helpUrl: '/admin/popuphelp.php?page=maintenance',
        phpwgUrl: 'https://piwigo.example',
        pwgVersion: '16.3.0',
        checkUpgradeUrl: '/admin.php?page=maintenance&action=check_upgrade',
        os: 'Linux',
        containerInfo: 'Official 1.0',
        phpVersion: '8.5.0',
        dbEngine: 'MySQL',
        dbVersion: '8.0.0',
        phpinfoUrl: '/admin.php?page=maintenance&action=phpinfo',
        phpCurrentTimestamp: '2026-08-08 00:00:00',
        dbCurrentDate: '2026-08-08 00:00:00',
        cacheSizes: null,
        timeElapsedSinceLastCalc: null,
        graphicsLibrary: null,
        maintUnlockGallery: null,
        maintLockGallery: null,
        installedOn: null,
        installedSince: null,
        advancedFeatures: [],
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKeys(['GRAPHICS_LIBRARY', 'U_MAINT_UNLOCK_GALLERY', 'U_MAINT_LOCK_GALLERY', 'INSTALLED_ON', 'INSTALLED_SINCE'])
        ->and($result['U_MAINT_CATEGORIES'])->toBe('/admin.php?page=maintenance&action=categories')
        ->and($result['purge_derivatives'])->toBe([
            'All' => '/admin.php?page=maintenance&action=derivatives&type=all',
        ])
        ->and($result['DB_DATATIME'])->toBe('2026-08-08 00:00:00')
        ->and($result['cache_sizes'])->toBeNull()
        ->and($result['advanced_features'])->toBe([]);
});

test('toArray includes GRAPHICS_LIBRARY/U_MAINT_UNLOCK_GALLERY/INSTALLED_ON/INSTALLED_SINCE when set', function (): void {
    $context = new MaintenanceEnvPageContext(
        maintCategories: '/admin.php?page=maintenance&action=categories',
        maintImages: '/admin.php?page=maintenance&action=images',
        maintOrphanTags: '/admin.php?page=maintenance&action=delete_orphan_tags',
        maintUserCache: '/admin.php?page=maintenance&action=user_cache',
        maintHistoryDetail: '/admin.php?page=maintenance&action=history_detail',
        maintHistorySummary: '/admin.php?page=maintenance&action=history_summary',
        maintSessions: '/admin.php?page=maintenance&action=sessions',
        maintFeeds: '/admin.php?page=maintenance&action=feeds',
        maintDatabase: '/admin.php?page=maintenance&action=database',
        maintC13y: '/admin.php?page=maintenance&action=c13y',
        maintSearch: '/admin.php?page=maintenance&action=search',
        maintCompiledTemplates: '/admin.php?page=maintenance&action=compiled-templates',
        maintDerivatives: '/admin.php?page=maintenance&action=derivatives',
        purgeDerivatives: [
            'All' => '/admin.php?page=maintenance&action=derivatives&type=all',
        ],
        helpUrl: '/admin/popuphelp.php?page=maintenance',
        phpwgUrl: 'https://piwigo.example',
        pwgVersion: '16.3.0',
        checkUpgradeUrl: '/admin.php?page=maintenance&action=check_upgrade',
        os: 'Linux',
        containerInfo: 'Official 1.0',
        phpVersion: '8.5.0',
        dbEngine: 'MySQL',
        dbVersion: '8.0.0',
        phpinfoUrl: '/admin.php?page=maintenance&action=phpinfo',
        phpCurrentTimestamp: '2026-08-08 00:00:00',
        dbCurrentDate: '2026-08-08 00:00:00',
        cacheSizes: null,
        timeElapsedSinceLastCalc: null,
        graphicsLibrary: 'ImageMagick',
        maintUnlockGallery: '/admin.php?page=maintenance&action=unlock_gallery',
        maintLockGallery: null,
        installedOn: 'August 8, 2026',
        installedSince: '0 day',
        advancedFeatures: [],
    );

    $result = $context->toArray();

    expect($result['GRAPHICS_LIBRARY'])->toBe('ImageMagick')
        ->and($result['U_MAINT_UNLOCK_GALLERY'])->toBe('/admin.php?page=maintenance&action=unlock_gallery')
        ->and($result)
        ->not->toHaveKey('U_MAINT_LOCK_GALLERY')
        ->and($result['INSTALLED_ON'])->toBe('August 8, 2026')
        ->and($result['INSTALLED_SINCE'])->toBe('0 day');
});
