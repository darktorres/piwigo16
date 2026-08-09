<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatListCategoriesPageContext;

test('toArray includes an empty categories list and omits PARENT_EDIT when null', function (): void {
    expect((new CatListCategoriesPageContext(parentEditUrl: null, categories: []))->toArray())
        ->toBe(['categories' => []]);
});

test('toArray includes PARENT_EDIT and the real categories list when set', function (): void {
    expect((new CatListCategoriesPageContext(parentEditUrl: '/admin.php?page=album-3', categories: [['ID' => 3, 'NAME' => 'Holidays']]))->toArray())
        ->toBe(['categories' => [['ID' => 3, 'NAME' => 'Holidays']], 'PARENT_EDIT' => '/admin.php?page=album-3']);
});
