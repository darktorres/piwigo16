<?php

declare(strict_types=1);

use Piwigo\Admin\Projection\CatModifyPageContext;

test('toArray flattens every fixed property, and omits every optional key when null', function (): void {
    $context = new CatModifyPageContext(
        categoriesNav: 'Home',
        categoriesParentNav: 'Root',
        parentCatId: 0,
        catId: 5,
        catName: 'Holidays',
        catComment: '',
        isVisible: true,
        catAdminAccess: true,
        uDelete: '/admin.php?page=albums',
        uJumpto: '/index.php?/category/5',
        uAddPhotosAlbum: '/admin.php?page=photos_add&album=5',
        uChildren: '/admin.php?page=albums&parent_id=5',
        uMove: '/admin.php?page=albums&parent_id=5',
        uActivity: '/admin.php?page=user_activity&album=5',
        catCommentable: null,
        uManageElements: null,
        infoPhoto: '10 photos',
        infoTitle: 'This album contains 10 photos',
        infoCreationSince: null,
        infoCreation: null,
        infoDirectSub: '2 sub-albums',
        infoId: 'Numeric identifier : 5',
        infoLastModifiedSince: '3 days',
        infoLastModified: 'August 5, 2026',
        infoImagesRecursive: '12 including sub-albums',
        infoSubcats: '2 in whole branch',
        nbSubcats: 2,
        uManageRanks: '/admin.php?page=element_set_ranks&cat_id=5',
        cacheKeys: [
            'categories' => 'abc',
        ],
        catFullDir: null,
        catDirName: null,
        catMinDir: null,
        uSync: null,
        representant: null,
        parentCategory: null,
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result)
        ->not->toHaveKeys(['CAT_COMMENTABLE', 'U_MANAGE_ELEMENTS', 'INFO_CREATION_SINCE', 'INFO_CREATION', 'CAT_FULL_DIR', 'CAT_DIR_NAME', 'CAT_MIN_DIR', 'U_SYNC', 'representant', 'parent_category'])
        ->and($result['CAT_ID'])->toBe(5)
        ->and($result['CAT_NAME'])->toBe('Holidays')
        ->and($result['CSRF_TOKEN'])->toBe('abc123');
});

test('toArray includes every optional key when set', function (): void {
    $context = new CatModifyPageContext(
        categoriesNav: 'Home',
        categoriesParentNav: 'Root',
        parentCatId: 3,
        catId: 5,
        catName: 'Holidays',
        catComment: 'Summer 2026',
        isVisible: true,
        catAdminAccess: true,
        uDelete: '/admin.php?page=albums',
        uJumpto: '/index.php?/category/5',
        uAddPhotosAlbum: '/admin.php?page=photos_add&album=5',
        uChildren: '/admin.php?page=albums&parent_id=5',
        uMove: '/admin.php?page=albums&parent_id=5',
        uActivity: '/admin.php?page=user_activity&album=5',
        catCommentable: true,
        uManageElements: '/admin.php?page=batch_manager&filter=album-5',
        infoPhoto: '10 photos',
        infoTitle: 'This album contains 10 photos',
        infoCreationSince: '2 weeks',
        infoCreation: 'July 25, 2026',
        infoDirectSub: '2 sub-albums',
        infoId: 'Numeric identifier : 5',
        infoLastModifiedSince: '3 days',
        infoLastModified: 'August 5, 2026',
        infoImagesRecursive: '12 including sub-albums',
        infoSubcats: '2 in whole branch',
        nbSubcats: 2,
        uManageRanks: '/admin.php?page=element_set_ranks&cat_id=5',
        cacheKeys: [],
        catFullDir: 'galleries/holidays',
        catDirName: 'holidays',
        catMinDir: 'galleries/holidays',
        uSync: '/admin.php?page=site_update&site=1&cat_id=5',
        representant: [
            'ALLOW_SET_RANDOM' => true,
        ],
        parentCategory: [3],
        pwgToken: 'abc123',
    );

    $result = $context->toArray();

    expect($result['CAT_COMMENTABLE'])->toBe(true)
        ->and($result['U_MANAGE_ELEMENTS'])->toBe('/admin.php?page=batch_manager&filter=album-5')
        ->and($result['CAT_FULL_DIR'])->toBe('galleries/holidays')
        ->and($result['representant'])->toBe([
            'ALLOW_SET_RANDOM' => true,
        ])
        ->and($result['parent_category'])->toBe([3]);
});
