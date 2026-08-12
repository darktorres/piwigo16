<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\LanguagesInstalledPageContext;

test('toArray flattens every property to its real Latte template variable name, casting isWebmaster to an int', function (): void {
    $context = new LanguagesInstalledPageContext(
        languages: [[
            'ID' => 'en_UK',
        ]],
        isWebmaster: true,
        adminPageTitle: 'Languages',
        enableExtensionsInstall: false,
    );

    expect($context->toArray())
        ->toBe([
            'languages' => [[
                'ID' => 'en_UK',
            ]],
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Languages',
            'CONF_ENABLE_EXTENSIONS_INSTALL' => false,
            'language_states' => ['active', 'inactive'],
        ]);
});
