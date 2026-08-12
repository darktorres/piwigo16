<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Controller\Projection\RegisterPageContext;

test('toArray flattens every property to its real Latte template variable name', function (): void {
    $context = new RegisterPageContext(
        homeUrl: '/index.php',
        formKey: 'key123',
        formAction: 'register.php',
        formLogin: 'alice',
        formEmail: 'alice@example.test',
        obligatoryUserMailAddress: true,
        languageOptions: [
            'en_UK' => 'English',
        ],
        currentLanguage: LangCode::from('en_UK'),
        helpLink: 'https://upstream.example.invalid/help/',
    );

    expect($context->toArray())
        ->toBe([
            'U_HOME' => '/index.php',
            'F_KEY' => 'key123',
            'F_ACTION' => 'register.php',
            'F_LOGIN' => 'alice',
            'F_EMAIL' => 'alice@example.test',
            'obligatory_user_mail_address' => true,
            'language_options' => [
                'en_UK' => 'English',
            ],
            'current_language' => 'en_UK',
            'HELP_LINK' => 'https://upstream.example.invalid/help/',
        ]);
});
