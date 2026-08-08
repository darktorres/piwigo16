<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatListHeaderPageContext;

test('toArray flattens every property to its real Smarty template variable name', function (): void {
    $context = new CatListHeaderPageContext(
        adminPageTitle: 'Album list management',
        categoriesNav: '<a href="/admin.php">Home</a>',
        formAction: '/admin.php?page=cat_list',
        pwgToken: 'token123',
        sortOrders: ['name ASC' => 'Album name, A to Z'],
        sortOrderChecked: 'name ASC',
    );

    expect($context->toArray())->toBe([
        'ADMIN_PAGE_TITLE' => 'Album list management',
        'CATEGORIES_NAV' => '<a href="/admin.php">Home</a>',
        'F_ACTION' => '/admin.php?page=cat_list',
        'PWG_TOKEN' => 'token123',
        'sort_orders' => ['name ASC' => 'Album name, A to Z'],
        'sort_order_checked' => 'name ASC',
    ]);
});
