<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\ThemesSubControllerPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new ThemesSubControllerPageContext(adminPageTitle: 'Themes'))->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Themes',
        ]);
});
