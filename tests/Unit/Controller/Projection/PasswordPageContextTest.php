<?php

declare(strict_types=1);

use Piwigo\Controller\Projection\PasswordPageContext;

test('toArray flattens every fixed property, and omits key/username_or_email/is_first_login when null', function (): void {
    $context = new PasswordPageContext(
        key: null,
        usernameOrEmail: null,
        isFirstLogin: null,
        title: 'Forgot your password?',
        formAction: '/password.php',
        action: 'lost',
        username: null,
        pwgToken: 'abc123',
        languageOptions: [
            'en_GB' => 'English',
        ],
        currentLanguage: 'en_GB',
        helpLink: 'https://upstream.example.invalid/help/',
    );

    expect($context->toArray())
        ->toBe([
            'title' => 'Forgot your password?',
            'form_action' => '/password.php',
            'action' => 'lost',
            'username' => null,
            'PWG_TOKEN' => 'abc123',
            'language_options' => [
                'en_GB' => 'English',
            ],
            'current_language' => 'en_GB',
            'HELP_LINK' => 'https://upstream.example.invalid/help/',
        ]);
});

test('toArray includes key/username_or_email/is_first_login when set', function (): void {
    $context = new PasswordPageContext(
        key: 'abcdef0123456789abcd',
        usernameOrEmail: 'jane',
        isFirstLogin: true,
        title: 'Welcome',
        formAction: '/password.php',
        action: 'reset',
        username: 'jane',
        pwgToken: 'abc123',
        languageOptions: [
            'en_GB' => 'English',
        ],
        currentLanguage: 'en_GB',
        helpLink: 'https://upstream.example.invalid/help/',
    );

    expect($context->toArray())
        ->toBe([
            'title' => 'Welcome',
            'form_action' => '/password.php',
            'action' => 'reset',
            'username' => 'jane',
            'PWG_TOKEN' => 'abc123',
            'key' => 'abcdef0123456789abcd',
            'username_or_email' => 'jane',
            'is_first_login' => true,
            'language_options' => [
                'en_GB' => 'English',
            ],
            'current_language' => 'en_GB',
            'HELP_LINK' => 'https://upstream.example.invalid/help/',
        ]);
});
