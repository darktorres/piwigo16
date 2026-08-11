<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ThemesStandardPagesPageContext;

test('toArray flattens every fixed property, and omits save_error when null', function (): void {
    $context = new ThemesStandardPagesPageContext(
        useStandardPages: true,
        stdPgsSelectedLogo: 'piwigo_logo',
        stdPgsLogoOptions: ['piwigo_logo', 'custom_logo', 'gallery_title', 'none'],
        stdPgsSelectedSkin: 'default',
        stdPgsSkinOptions: ['default', 'cadmium'],
        isStandardPagesUsed: true,
        standardPagesUsedBy: ['Elegant'],
        stdPgsSelectedLogoPath: null,
        pwgToken: 'abc123',
        isWebmaster: 1,
        adminPageTitle: 'Themes',
        saveError: null,
    );

    expect($context->toArray())
        ->toBe([
            'use_standard_pages' => true,
            'std_pgs_selected_logo' => 'piwigo_logo',
            'std_pgs_logo_options' => ['piwigo_logo', 'custom_logo', 'gallery_title', 'none'],
            'std_pgs_selected_skin' => 'default',
            'std_pgs_skin_options' => ['default', 'cadmium'],
            'is_standard_pages_used' => true,
            'standard_pages_used_by' => ['Elegant'],
            'std_pgs_selected_logo_path' => null,
            'PWG_TOKEN' => 'abc123',
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Themes',
        ]);
});

test('toArray includes save_error when set', function (): void {
    $context = new ThemesStandardPagesPageContext(
        useStandardPages: false,
        stdPgsSelectedLogo: 'none',
        stdPgsLogoOptions: ['none'],
        stdPgsSelectedSkin: 'default',
        stdPgsSkinOptions: ['default'],
        isStandardPagesUsed: false,
        standardPagesUsedBy: [],
        stdPgsSelectedLogoPath: '/logo.php',
        pwgToken: 'abc123',
        isWebmaster: 0,
        adminPageTitle: 'Themes',
        saveError: 'Invalid image file.',
    );

    expect($context->toArray())
        ->toBe([
            'use_standard_pages' => false,
            'std_pgs_selected_logo' => 'none',
            'std_pgs_logo_options' => ['none'],
            'std_pgs_selected_skin' => 'default',
            'std_pgs_skin_options' => ['default'],
            'is_standard_pages_used' => false,
            'standard_pages_used_by' => [],
            'std_pgs_selected_logo_path' => '/logo.php',
            'PWG_TOKEN' => 'abc123',
            'isWebmaster' => 0,
            'ADMIN_PAGE_TITLE' => 'Themes',
            'save_error' => 'Invalid image file.',
        ]);
});
