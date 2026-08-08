<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatListCategoriesPageContext;

test('toArray seeds categories empty and omits PARENT_EDIT when null', function (): void {
    expect((new CatListCategoriesPageContext(parentEditUrl: null))->toArray())
        ->toBe(['categories' => []]);
});

test('toArray includes PARENT_EDIT when set', function (): void {
    expect((new CatListCategoriesPageContext(parentEditUrl: '/admin.php?page=album-3'))->toArray())
        ->toBe(['categories' => [], 'PARENT_EDIT' => '/admin.php?page=album-3']);
});
