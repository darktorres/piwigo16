<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ConfigurationPageContext;

test('toArray flattens every fixed property, and omits save_success when null', function (): void {
    $context = new ConfigurationPageContext(
        saveSuccess: null,
        uHelp: '/admin/popuphelp.php?page=configuration',
        pwgToken: 'abc123',
        fAction: '/admin.php?page=configuration&section=main',
        isWebmaster: 1,
        adminPageTitle: 'Configuration',
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKey('save_success')
        ->and($result['U_HELP'])->toBe('/admin/popuphelp.php?page=configuration')
        ->and($result['CSRF_TOKEN'])->toBe('abc123')
        ->and($result['F_ACTION'])->toBe('/admin.php?page=configuration&section=main')
        ->and($result['isWebmaster'])->toBe(1)
        ->and($result['ADMIN_PAGE_TITLE'])->toBe('Configuration');
});

test('toArray includes save_success when set', function (): void {
    $context = new ConfigurationPageContext(
        saveSuccess: 'Your configuration settings are saved',
        uHelp: '/admin/popuphelp.php?page=configuration',
        pwgToken: 'abc123',
        fAction: '/admin.php?page=configuration&section=main',
        isWebmaster: 0,
        adminPageTitle: 'Configuration',
    );

    expect($context->toArray()['save_success'])->toBe('Your configuration settings are saved');
});
