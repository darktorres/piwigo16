<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CategoryListRow;
use Piwigo\Admin\Projection\CatListCategoriesPageContext;

test('toArray includes an empty categories list and omits PARENT_EDIT when null', function (): void {
    expect(new CatListCategoriesPageContext(parentEditUrl: null, categories: [])->toArray())
        ->toBe([
            'categories' => [],
        ]);
});

test('toArray includes PARENT_EDIT and the real categories list when set', function (): void {
    $row = new CategoryListRow(
        name: 'Holidays',
        nbPhotos: 4,
        nbSubPhotos: 0,
        nbSubAlbums: 0,
        id: 3,
        rank: 10,
        uJumpto: '/index.php?/category/3',
        uChildren: '/admin.php?page=cat_list&parent_id=3',
        uEdit: '/admin.php?page=album-3',
        uAddPhotosAlbum: '/admin.php?page=photos_add&album=3',
        uMove: '/admin.php?page=albums#cat-3',
        isVirtual: false,
        catAdminAccess: true,
        uDelete: null,
        uSync: '/admin.php?page=site_update&site=1&cat_id=3',
    );

    expect(new CatListCategoriesPageContext(parentEditUrl: '/admin.php?page=album-3', categories: [$row])->toArray())
        ->toBe([
            'categories' => [[
                'NAME' => 'Holidays',
                'NB_PHOTOS' => 4,
                'NB_SUB_PHOTOS' => 0,
                'NB_SUB_ALBUMS' => 0,
                'ID' => 3,
                'RANK' => 10,
                'U_JUMPTO' => '/index.php?/category/3',
                'U_CHILDREN' => '/admin.php?page=cat_list&parent_id=3',
                'U_EDIT' => '/admin.php?page=album-3',
                'U_ADD_PHOTOS_ALBUM' => '/admin.php?page=photos_add&album=3',
                'U_MOVE' => '/admin.php?page=albums#cat-3',
                'IS_VIRTUAL' => false,
                'CAT_ADMIN_ACCESS' => true,
                'U_SYNC' => '/admin.php?page=site_update&site=1&cat_id=3',
            ]],
            'PARENT_EDIT' => '/admin.php?page=album-3',
        ]);
});
