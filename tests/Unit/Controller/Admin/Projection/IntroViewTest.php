<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\IntroView;

/**
 * @param array<string, array<string, array<string, mixed>>> $storageChartData
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
        'Photos' => [],
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
        'Photos' => [],
        'Videos' => [],
    ]);

    expect($view->exposedStrings())
        ->toContain('Photos', 'Videos');
});
