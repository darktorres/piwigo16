<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\UpdatesExtPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new UpdatesExtPageContext(
        updatesExtension: [
            'plugins' => [[
                'ID' => 'foo',
                'EXT_NAME' => 'Foo',
            ]],
        ],
        showReset: true,
        pwgToken: 'abc123',
        extType: 'extensions',
        isWebmaster: 1,
        adminPageTitle: 'Updates',
    );

    expect($context->toArray())
        ->toBe([
            'UPDATES_EXTENSION' => [
                'plugins' => [[
                    'ID' => 'foo',
                    'EXT_NAME' => 'Foo',
                ]],
            ],
            'SHOW_RESET' => true,
            'PWG_TOKEN' => 'abc123',
            'EXT_TYPE' => 'extensions',
            'isWebmaster' => 1,
            'ADMIN_PAGE_TITLE' => 'Updates',
        ]);
});
