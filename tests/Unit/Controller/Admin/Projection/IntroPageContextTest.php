<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\IntroPageContext;

test('toArray flattens every fixed property, and omits EMAIL/SUBSCRIBE_BASE_URL/OLD_NEWSLETTERS_URL when null', function (): void {
    $context = new IntroPageContext(
        email: null,
        subscribeBaseUrl: null,
        oldNewslettersUrl: null,
        nbPhotos: 10,
        nbAlbums: 3,
        nbTags: 5,
        nbImageTag: 20,
        nbUsers: 2,
        nbGroups: 1,
        nbRates: 0,
        nbViews: '1.2K',
        nbPlugins: 4,
        storageUsed: '1.5&nbsp;GB',
        uQuickSync: '/admin.php?page=site_update&site=1&quick_sync=1&pwg_token=abc',
        checkForUpdates: true,
        nbComments: 2,
        activityWeekNumber: ['32', '33'],
        activityLastWeeks: [],
        activityChartData: [],
        activityChartNumberSizes: 1,
        dayLabels: ['Mon', 'Tue'],
        storageTotal: 123.4,
        storageChartData: [],
    );

    $result = $context->toArray();

    expect($result)->not->toHaveKeys(['EMAIL', 'SUBSCRIBE_BASE_URL', 'OLD_NEWSLETTERS_URL'])
        ->and($result['NB_PHOTOS'])->toBe(10)
        ->and($result['NB_VIEWS'])->toBe('1.2K')
        ->and($result['STORAGE_TOTAL'])->toBe(123.4);
});

test('toArray includes EMAIL/SUBSCRIBE_BASE_URL/OLD_NEWSLETTERS_URL when set', function (): void {
    $context = new IntroPageContext(
        email: 'admin@example.test',
        subscribeBaseUrl: 'https://piwigo.example/announcement/subscribe/',
        oldNewslettersUrl: 'https://piwigo.example/newsletter',
        nbPhotos: 0,
        nbAlbums: 0,
        nbTags: 0,
        nbImageTag: 0,
        nbUsers: 1,
        nbGroups: 0,
        nbRates: 0,
        nbViews: '0',
        nbPlugins: 0,
        storageUsed: '0&nbsp;GB',
        uQuickSync: '/admin.php',
        checkForUpdates: false,
        nbComments: 0,
        activityWeekNumber: [],
        activityLastWeeks: [],
        activityChartData: [],
        activityChartNumberSizes: 1,
        dayLabels: [],
        storageTotal: 0.0,
        storageChartData: [],
    );

    $result = $context->toArray();

    expect($result['EMAIL'])->toBe('admin@example.test')
        ->and($result['SUBSCRIBE_BASE_URL'])->toBe('https://piwigo.example/announcement/subscribe/')
        ->and($result['OLD_NEWSLETTERS_URL'])->toBe('https://piwigo.example/newsletter');
});
