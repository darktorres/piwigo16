<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\LanguagesNewPageContext;

test('toArray flattens the admin page title and casts isWebmaster to an int', function (): void {
    expect((new LanguagesNewPageContext(adminPageTitle: 'Languages', isWebmaster: true))->toArray())
        ->toBe(['ADMIN_PAGE_TITLE' => 'Languages', 'isWebmaster' => 1]);

    expect((new LanguagesNewPageContext(adminPageTitle: 'Languages', isWebmaster: false))->toArray()['isWebmaster'])
        ->toBe(0);
});
