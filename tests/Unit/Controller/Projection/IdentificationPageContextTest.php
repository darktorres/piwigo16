<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\IdentificationPageContext;

test('toArray flattens every fixed property, and omits U_REGISTER/U_LOST_PASSWORD when null', function (): void {
    $context = new IdentificationPageContext(
        redirect: '',
        loginAction: '/identification.php',
        authorizeRemembering: true,
        register: null,
        lostPassword: null,
        languageOptions: ['en_GB' => 'English', 'fr_FR' => 'French'],
        currentLanguage: 'en_GB',
        helpLink: 'https://upstream.example.invalid/help/',
    );

    expect($context->toArray())->toBe([
        'U_REDIRECT' => '',
        'F_LOGIN_ACTION' => '/identification.php',
        'authorize_remembering' => true,
        'language_options' => ['en_GB' => 'English', 'fr_FR' => 'French'],
        'current_language' => 'en_GB',
        'HELP_LINK' => 'https://upstream.example.invalid/help/',
    ]);
});

test('toArray includes U_REGISTER/U_LOST_PASSWORD when set', function (): void {
    $context = new IdentificationPageContext(
        redirect: '/admin.php',
        loginAction: '/identification.php',
        authorizeRemembering: false,
        register: '/register.php',
        lostPassword: '/password.php',
        languageOptions: ['fr_FR' => 'French'],
        currentLanguage: 'fr_FR',
        helpLink: 'https://upstream.example.invalid/help/fr/',
    );

    expect($context->toArray())->toBe([
        'U_REDIRECT' => '/admin.php',
        'F_LOGIN_ACTION' => '/identification.php',
        'authorize_remembering' => false,
        'U_REGISTER' => '/register.php',
        'U_LOST_PASSWORD' => '/password.php',
        'language_options' => ['fr_FR' => 'French'],
        'current_language' => 'fr_FR',
        'HELP_LINK' => 'https://upstream.example.invalid/help/fr/',
    ]);
});
