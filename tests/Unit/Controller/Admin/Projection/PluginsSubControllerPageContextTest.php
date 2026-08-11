<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Projection\PluginsSubControllerPageContext;

test('toArray flattens the admin page title', function (): void {
    expect((new PluginsSubControllerPageContext(adminPageTitle: 'Plugins'))->toArray())
        ->toBe([
            'ADMIN_PAGE_TITLE' => 'Plugins',
        ]);
});
