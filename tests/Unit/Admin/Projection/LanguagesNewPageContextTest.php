<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\LanguagesNewPageContext;

test('toArray flattens the admin page title and casts isWebmaster to an int', function (): void {
    expect(new LanguagesNewPageContext(adminPageTitle: 'Languages', isWebmaster: true, languages: [])->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Languages',
            'isWebmaster' => 1,
            'languages' => [],
        ]);

    expect(new LanguagesNewPageContext(adminPageTitle: 'Languages', isWebmaster: false, languages: [])->toArray()['isWebmaster'])
        ->toBe(0);
});

test('toArray includes the real languages list when set', function (): void {
    $result = new LanguagesNewPageContext(
        adminPageTitle: 'Languages',
        isWebmaster: true,
        languages: [[
            'EXT_NAME' => 'French',
        ]],
    )->toArray();

    expect($result['languages'])->toBe([[
        'EXT_NAME' => 'French',
    ]]);
});
