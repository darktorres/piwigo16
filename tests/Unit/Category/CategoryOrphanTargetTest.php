<?php

declare(strict_types=1);

use Piwigo\Category\CategoryOrphanTarget;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Piwigo\Category\CategoryOrphanTarget -- CategoryRepository::
 * findOrphanedColumnValues()/deleteRowsWhereColumnIn()'s $table/$column
 * pairs, enumerated. No dedicated Integration/Browser spec of its own.
 *
 * tableAndColumn()'s expected table names are computed via the same
 * real Tables::*() static methods the enum itself calls, rather than
 * hardcoded literals -- proves the right table method was picked (a
 * typo swapping e.g. imageCategory() for userAccess() would still fail
 * this comparison), without duplicating "what's the real table prefix"
 * knowledge.
 */
test('tableAndColumn maps every case to its real table/column pair', function (): void {
    expect(CategoryOrphanTarget::ImageCategory->tableAndColumn()->table)->toBe(Tables::imageCategory())
        ->and(CategoryOrphanTarget::ImageCategory->tableAndColumn()->column)->toBe('category_id')
        ->and(CategoryOrphanTarget::UserAccess->tableAndColumn()->table)->toBe(Tables::userAccess())
        ->and(CategoryOrphanTarget::UserAccess->tableAndColumn()->column)->toBe('cat_id')
        ->and(CategoryOrphanTarget::GroupAccess->tableAndColumn()->table)->toBe(Tables::groupAccess())
        ->and(CategoryOrphanTarget::GroupAccess->tableAndColumn()->column)->toBe('cat_id')
        ->and(CategoryOrphanTarget::OldPermalinks->tableAndColumn()->table)->toBe(Tables::oldPermalinks())
        ->and(CategoryOrphanTarget::OldPermalinks->tableAndColumn()->column)->toBe('cat_id');
});

test('entityClassAndProperty maps every DQL-backed case to its real entity/property pair, and OldPermalinks to null', function (): void {
    $imageCategory = CategoryOrphanTarget::ImageCategory->entityClassAndProperty();
    expect($imageCategory)->not->toBeNull();
    if ($imageCategory !== null) {
        expect($imageCategory->entityClass)->toBe(ImageCategoryEntity::class)
            ->and($imageCategory->property)->toBe('categoryId');
    }

    $userAccess = CategoryOrphanTarget::UserAccess->entityClassAndProperty();
    expect($userAccess)->not->toBeNull();
    if ($userAccess !== null) {
        expect($userAccess->entityClass)->toBe(UserAccessEntity::class)
            ->and($userAccess->property)->toBe('catId');
    }

    $groupAccess = CategoryOrphanTarget::GroupAccess->entityClassAndProperty();
    expect($groupAccess)->not->toBeNull();
    if ($groupAccess !== null) {
        expect($groupAccess->entityClass)->toBe(GroupAccessEntity::class)
            ->and($groupAccess->property)->toBe('catId');
    }

    expect(CategoryOrphanTarget::OldPermalinks->entityClassAndProperty())->toBeNull();
});
