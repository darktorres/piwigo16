<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\ProfileFormView;

function makeProfileFormView(): ProfileFormView
{
    return new ProfileFormView(
        fAction: 'profile.php',
        redirect: '',
        username: 'alice',
        specialUser: false,
        email: 'alice@example.com',
        allowUserCustomization: true,
        nbImagePage: 15,
        templateOptions: [],
        templateSelection: '',
        languageOptions: [],
        languageSelection: 'en_UK',
        recentPeriod: 7,
        radioOptions: [],
        expand: 'false',
        activateComments: true,
        nbComments: 'false',
        nbHits: 'false',
        csrfToken: 'token',
        pluginProfileFields: [],
        pluginFieldOverrides: [],
        pluginFormProviders: [],
    );
}

test('exposedStrings returns the password-match translated string', function (): void {
    $view = makeProfileFormView();

    expect($view->exposedStrings())
        ->toBe(['The passwords do not match']);
});

test('exposedPageData returns no dynamic data', function (): void {
    $view = makeProfileFormView();

    expect($view->exposedPageData())
        ->toBe([]);
});
