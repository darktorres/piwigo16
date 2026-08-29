<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\IntroView;

/**
 * @param array<string, array{total: array{filesize: float, nb_files?: int}, details?: array<string, array{filesize: float, nb_files: int}>}> $storageChartData
 *   only the keys matter to exposedStrings(), which names one string
 *   per storage type, so each entry here carries the minimum a real
 *   one does: a total filesize and nothing else, the shape the
 *   'Cache' type genuinely has.
 */
function makeIntroView(?string $subscribeBaseUrl, array $storageChartData): IntroView
{
    return new IntroView(
        email: $subscribeBaseUrl !== null ? 'admin@example.test' : null,
        subscribeBaseUrl: $subscribeBaseUrl,
        oldNewslettersUrl: $subscribeBaseUrl !== null ? 'https://example.test/newsletters' : null,
        nbPhotos: 0,
        nbAlbums: 0,
        nbTags: 0,
        nbImageTag: 0,
        nbUsers: 0,
        nbGroups: 0,
        nbRates: 0,
        nbViews: '0',
        nbPlugins: 0,
        storageUsed: '0GB',
        uQuickSync: '',
        checkForUpdates: false,
        nbComments: 0,
        activityWeekNumber: [],
        activityLastWeeks: [],
        activityChartData: [],
        activityChartNumberSizes: 0,
        dayLabels: [],
        storageTotal: 0.0,
        storageChartData: $storageChartData,
    );
}

test('exposedStrings excludes the newsletter-promo strings when subscribeBaseUrl is null', function (): void {
    $view = makeIntroView(subscribeBaseUrl: null, storageChartData: [
        'Photos' => [
            'total' => [
                'filesize' => 0.0,
            ],
        ],
    ]);

    expect($view->exposedStrings())
        ->toBe([
            'A new version of Piwigo is available.',
            'Some upgrades are available for extensions.',
            '%s GB used',
            '%s MB used',
            '%sGB',
            '%sMB',
            '%d files',
            'Photos',
        ]);
});

test('exposedStrings includes the newsletter-promo strings when subscribeBaseUrl is set', function (): void {
    $view = makeIntroView(subscribeBaseUrl: 'https://example.test/subscribe', storageChartData: []);

    expect($view->exposedStrings())
        ->toBe([
            'A new version of Piwigo is available.',
            'Some upgrades are available for extensions.',
            '%s GB used',
            '%s MB used',
            '%sGB',
            '%sMB',
            '%d files',
            'Subscribe to our newsletter and stay updated!',
            'Sign up to the newsletter',
            'See previous newsletters',
            'Understood, do not show again',
        ]);
});

test('exposedStrings exposes every storageChartData key', function (): void {
    $view = makeIntroView(subscribeBaseUrl: null, storageChartData: [
        'Photos' => [
            'total' => [
                'filesize' => 0.0,
            ],
        ],
        'Videos' => [
            'total' => [
                'filesize' => 0.0,
            ],
        ],
    ]);

    expect($view->exposedStrings())
        ->toContain('Photos', 'Videos');
});
