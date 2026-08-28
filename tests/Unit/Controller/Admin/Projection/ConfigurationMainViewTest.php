<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationMainData;
use Piwigo\Controller\Admin\Projection\ConfigurationMainView;

/**
 * @param array<string, string> $orderByOptions
 */
function makeConfigurationMainView(array $orderByOptions): ConfigurationMainView
{
    return new ConfigurationMainView(
        main: new ConfigurationMainData(
            confGalleryTitle: '',
            confPageBanner: '',
            weekStartsOnOptions: [],
            weekStartsOnOptionsSelected: '',
            mailTheme: '',
            mailThemeOptions: [],
            orderBy: [],
            orderByOptions: $orderByOptions,
            emailAdminOnNewUser: false,
            emailAdminOnNewUserFilter: '',
            emailAdminOnNewUserFilterGroup: 0,
            allowUserRegistration: false,
            obligatoryUserMailAddress: false,
            rate: false,
            rateAnonymous: false,
            allowUserCustomization: false,
            log: false,
            historyAdmin: false,
            historyGuest: false,
            showMobileAppBannerInGallery: false,
            showMobileAppBannerInAdmin: false,
            uploadDetectDuplicate: false,
        ),
        groupOptions: [],
        fAction: '',
        saveSuccess: null,
        isWebmaster: 0,
        csrfToken: '',
    );
}

test('exposedPageData counts order_by_options entries', function (): void {
    $view = makeConfigurationMainView([
        '' => '',
        'file ASC' => 'File name',
    ]);

    expect($view->exposedPageData())
        ->toBe([
            'order_by_options_count' => 2,
        ]);
});

// The sibling case this file used to carry -- "falls back to 0 when
// order_by_options is not an array" -- is gone with the array bag it tested.
// `$main` is a ConfigurationMainData now and `orderByOptions` is
// `array<string, string>`, so a non-array cannot be constructed and the
// `is_array()` guard it exercised no longer exists to be exercised. An empty
// map still counts 0, which is the reachable half of that case.
test('exposedPageData counts an empty order_by_options map as 0', function (): void {
    $view = makeConfigurationMainView([]);

    expect($view->exposedPageData())
        ->toBe([
            'order_by_options_count' => 0,
        ]);
});
