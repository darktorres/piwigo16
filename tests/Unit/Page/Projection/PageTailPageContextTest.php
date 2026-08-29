<?php

declare(strict_types=1);

use Piwigo\Page\Projection\DebugInfo;
use Piwigo\Page\Projection\PageTailPageContext;

test('toArray flattens every fixed property, and omits the 2 optional keys when null', function (): void {
    $debug = new DebugInfo();
    $context = new PageTailPageContext(
        version: '16.3.0',
        phpwgUrl: 'https://piwigo.example',
        vitalsScriptUrl: '/dist/vitals.js',
        contactMail: null,
        debug: $debug,
        toggleMobileThemeUrl: null,
    );

    // The debug info reaches both layouts as the object itself, not
    // flattened -- the identity assertion is what says so; an equality
    // one would still pass against a flatten that round-tripped.
    expect($context->toArray())
        ->toBe([
            'VERSION' => '16.3.0',
            'APP_URL' => 'https://piwigo.example',
            'VITALS_SCRIPT_URL' => '/dist/vitals.js',
            'debug' => $debug,
        ]);
});

test('toArray includes CONTACT_MAIL/TOGGLE_MOBILE_THEME_URL when set, and passes the debug object through', function (): void {
    $debug = new DebugInfo(time: '0.123 s', nbQueries: 5, sqlTime: '0.045 s');
    $context = new PageTailPageContext(
        version: '16.3.0',
        phpwgUrl: 'https://piwigo.example',
        vitalsScriptUrl: '/dist/vitals.js',
        contactMail: 'webmaster@example.test',
        debug: $debug,
        toggleMobileThemeUrl: '/index.php?mobile=true',
    );

    expect($context->toArray())
        ->toBe([
            'VERSION' => '16.3.0',
            'APP_URL' => 'https://piwigo.example',
            'VITALS_SCRIPT_URL' => '/dist/vitals.js',
            'debug' => $debug,
            'CONTACT_MAIL' => 'webmaster@example.test',
            'TOGGLE_MOBILE_THEME_URL' => '/index.php?mobile=true',
        ]);
});
