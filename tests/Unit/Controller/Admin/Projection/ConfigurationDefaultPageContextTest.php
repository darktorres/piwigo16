<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationDefaultPageContext;

test('toArray flattens the GUEST_-prefixed Guest settings fields', function (): void {
    $context = new ConfigurationDefaultPageContext(
        username: 'guest',
        email: null,
        allowUserCustomization: true,
        activateComments: true,
        nbImagePage: 15,
        recentPeriod: 7,
        expand: 'false',
        nbComments: 'true',
        nbHits: 'true',
        redirect: '',
        fAction: 'admin.php?page=configuration&section=default',
        radioOptions: ['true' => 'Yes', 'false' => 'No'],
        csrfToken: 'a-token',
    );

    expect($context->toArray())
        ->toBe([
            'default' => [],
            'GUEST_USERNAME' => 'guest',
            'GUEST_EMAIL' => null,
            'GUEST_ALLOW_USER_CUSTOMIZATION' => true,
            'GUEST_ACTIVATE_COMMENTS' => true,
            'GUEST_NB_IMAGE_PAGE' => 15,
            'GUEST_RECENT_PERIOD' => 7,
            'GUEST_EXPAND' => 'false',
            'GUEST_NB_COMMENTS' => 'true',
            'GUEST_NB_HITS' => 'true',
            'GUEST_REDIRECT' => '',
            'GUEST_F_ACTION' => 'admin.php?page=configuration&section=default',
            'radio_options' => ['true' => 'Yes', 'false' => 'No'],
            'CSRF_TOKEN' => 'a-token',
        ]);
});
