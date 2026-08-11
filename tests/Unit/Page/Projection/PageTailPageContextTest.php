<?php

declare(strict_types=1);

use Piwigo\Page\Projection\PageTailPageContext;

test('toArray flattens every fixed property, and omits the 2 optional keys when null', function (): void {
    $context = new PageTailPageContext(
        version: '16.3.0',
        phpwgUrl: 'https://piwigo.example',
        vitalsScriptUrl: '/dist/vitals.js',
        contactMail: null,
        debug: [],
        toggleMobileThemeUrl: null,
    );

    expect($context->toArray())
        ->toBe([
            'VERSION' => '16.3.0',
            'PHPWG_URL' => 'https://piwigo.example',
            'VITALS_SCRIPT_URL' => '/dist/vitals.js',
            'debug' => [],
        ]);
});

test('toArray includes CONTACT_MAIL/TOGGLE_MOBILE_THEME_URL when set, and passes through the debug bag as-is', function (): void {
    $context = new PageTailPageContext(
        version: '16.3.0',
        phpwgUrl: 'https://piwigo.example',
        vitalsScriptUrl: '/dist/vitals.js',
        contactMail: 'webmaster@example.test',
        debug: [
            'TIME' => '0.123 s',
            'NB_QUERIES' => 5,
            'SQL_TIME' => '0.045 s',
        ],
        toggleMobileThemeUrl: '/index.php?mobile=true',
    );

    expect($context->toArray())
        ->toBe([
            'VERSION' => '16.3.0',
            'PHPWG_URL' => 'https://piwigo.example',
            'VITALS_SCRIPT_URL' => '/dist/vitals.js',
            'debug' => [
                'TIME' => '0.123 s',
                'NB_QUERIES' => 5,
                'SQL_TIME' => '0.045 s',
            ],
            'CONTACT_MAIL' => 'webmaster@example.test',
            'TOGGLE_MOBILE_THEME_URL' => '/index.php?mobile=true',
        ]);
});
