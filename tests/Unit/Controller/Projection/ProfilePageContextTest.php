<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Controller\Projection\ProfilePageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new ProfilePageContext(
        defaultUserValues: [
            'expand' => 'true',
        ],
        languageOptions: [
            'en_UK' => 'English',
            'fr_FR' => 'Français',
        ],
        languageSelection: LangCode::from('en_UK'),
        helpLink: 'https://upstream.example.invalid/help/',
    );

    expect($context->toArray())
        ->toBe([
            'DEFAULT_USER_VALUES' => [
                'expand' => 'true',
            ],
            'language_options' => [
                'en_UK' => 'English',
                'fr_FR' => 'Français',
            ],
            'language_selection' => 'en_UK',
            'HELP_LINK' => 'https://upstream.example.invalid/help/',
        ]);
});
