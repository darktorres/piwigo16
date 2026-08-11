<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\MaintenanceActionsPageContext;

test('toArray flattens every fixed property, and omits the 5 optional keys when null', function (): void {
    $context = new MaintenanceActionsPageContext(
        maintActions: [],
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
            'All' => 'all',
        ],
        helpUrl: '/admin/popuphelp.php?page=maintenance',
        phpwgUrl: 'https://piwigo.example',
        pwgVersion: '16.3.0',
        checkUpgradeUrl: '/admin.php?page=maintenance&action=check_upgrade',
        os: 'Linux',
        phpVersion: '8.5.0',
        dbEngine: 'MySQL',
        dbVersion: '8.0.0',
        phpinfoUrl: '/admin.php?page=maintenance&action=phpinfo',
        phpCurrentTimestamp: '2026-08-08 00:00:00',
        dbCurrentDate: '2026-08-08 00:00:00',
        pwgToken: 'abc123',
        cacheSizes: null,
        timeElapsedSinceLastCalc: null,
        graphicsLibrary: null,
        maintUnlockGallery: null,
        maintLockGallery: null,
        uEmptyLounge: null,
        loungeCounter: null,
        isWebmaster: 0,
        advancedFeatures: [],
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKeys(['GRAPHICS_LIBRARY', 'U_MAINT_UNLOCK_GALLERY', 'U_MAINT_LOCK_GALLERY', 'U_EMPTY_LOUNGE', 'LOUNGE_COUNTER'])
        ->and($result['U_MAINT_CATEGORIES'])->toBe('/admin.php?page=maintenance&action=categories')
        ->and($result['pwg_token'])->toBe('abc123')
        ->and($result['isWebmaster'])->toBe(0);
});

test('toArray includes GRAPHICS_LIBRARY/U_MAINT_UNLOCK_GALLERY/U_EMPTY_LOUNGE/LOUNGE_COUNTER when set', function (): void {
    $context = new MaintenanceActionsPageContext(
        maintActions: [],
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
            'All' => 'all',
        ],
        helpUrl: '/admin/popuphelp.php?page=maintenance',
        phpwgUrl: 'https://piwigo.example',
        pwgVersion: '16.3.0',
        checkUpgradeUrl: '/admin.php?page=maintenance&action=check_upgrade',
        os: 'Linux',
        phpVersion: '8.5.0',
        dbEngine: 'MySQL',
        dbVersion: '8.0.0',
        phpinfoUrl: '/admin.php?page=maintenance&action=phpinfo',
        phpCurrentTimestamp: '2026-08-08 00:00:00',
        dbCurrentDate: '2026-08-08 00:00:00',
        pwgToken: 'abc123',
        cacheSizes: null,
        timeElapsedSinceLastCalc: null,
        graphicsLibrary: 'ImageMagick 7.1.0',
        maintUnlockGallery: '/admin.php?page=maintenance&action=unlock_gallery',
        maintLockGallery: null,
        uEmptyLounge: '/admin.php?page=maintenance&action=empty_lounge',
        loungeCounter: 5,
        isWebmaster: 1,
        advancedFeatures: [],
    );

    $result = $context->toArray();

    expect($result['GRAPHICS_LIBRARY'])->toBe('ImageMagick 7.1.0')
        ->and($result['U_MAINT_UNLOCK_GALLERY'])->toBe('/admin.php?page=maintenance&action=unlock_gallery')
        ->and($result)
        ->not->toHaveKey('U_MAINT_LOCK_GALLERY')
        ->and($result['U_EMPTY_LOUNGE'])->toBe('/admin.php?page=maintenance&action=empty_lounge')
        ->and($result['LOUNGE_COUNTER'])->toBe(5);
});
