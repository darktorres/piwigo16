<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ThemesInstalledPageContext;

test('toArray flattens every property to its real Latte template variable name, casting isWebmaster to an int', function (): void {
    $context = new ThemesInstalledPageContext(
        activateBaseUrl: '/admin.php?page=themes&action=activate&theme=',
        deactivateBaseUrl: '/admin.php?page=themes&action=deactivate&theme=',
        setDefaultBaseUrl: '/admin.php?page=themes&action=set_default&theme=',
        deleteBaseUrl: '/admin.php?page=themes&action=delete&theme=',
        tplThemes: [[
            'ID' => 'roboticfarm',
        ]],
        isWebmaster: true,
        adminPageTitle: 'Themes',
        enableExtensionsInstall: false,
    );

    expect($context->toArray())
        ->toBe([
            'activate_baseurl' => '/admin.php?page=themes&action=activate&theme=',
            'deactivate_baseurl' => '/admin.php?page=themes&action=deactivate&theme=',
            'set_default_baseurl' => '/admin.php?page=themes&action=set_default&theme=',
            'delete_baseurl' => '/admin.php?page=themes&action=delete&theme=',
            'tpl_themes' => [[
                'ID' => 'roboticfarm',
            ]],
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Themes',
            'CONF_ENABLE_EXTENSIONS_INSTALL' => false,
        ]);
});
