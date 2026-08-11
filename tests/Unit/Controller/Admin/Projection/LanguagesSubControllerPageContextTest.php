<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\LanguagesSubControllerPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new LanguagesSubControllerPageContext(adminPageTitle: 'Languages'))->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Languages',
        ]);
});
