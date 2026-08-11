<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\ExtendForTemplatesPageContext;

test('toArray flattens every property, and omits extents when null', function (): void {
    $context = new ExtendForTemplatesPageContext(
        helpUrl: '/admin/popuphelp.php?page=extend_for_templates',
        adminPageTitle: 'Extend for templates',
        extents: null,
    );

    expect($context->toArray())
        ->toBe([
            'U_HELP' => '/admin/popuphelp.php?page=extend_for_templates',
            'ADMIN_PAGE_TITLE' => 'Extend for templates',
        ]);
});

test('toArray includes extents when set', function (): void {
    $context = new ExtendForTemplatesPageContext(
        helpUrl: '/admin/popuphelp.php?page=extend_for_templates',
        adminPageTitle: 'Extend for templates',
        extents: [[
            'replacer' => 'index.tpl',
            'url_parameter' => 'foo',
            'original_tpl' => ['index.tpl'],
            'bound_tpl' => ['index.tpl'],
            'selected_tpl' => 0,
            'selected_url' => 'N/A',
            'selected_bound' => 'N/A',
        ]],
    );

    expect($context->toArray()['extents'])->toBe([[
        'replacer' => 'index.tpl',
        'url_parameter' => 'foo',
        'original_tpl' => ['index.tpl'],
        'bound_tpl' => ['index.tpl'],
        'selected_tpl' => 0,
        'selected_url' => 'N/A',
        'selected_bound' => 'N/A',
    ]]);
});
