<?php

declare(strict_types=1);

use Piwigo\Category\CategoryOrphanTarget;
use Piwigo\Category\Projection\DqlPropertyTarget;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Piwigo\Category\CategoryOrphanTarget -- CategoryRepository::
 * findOrphanedColumnValues()/deleteRowsWhereColumnIn()'s $table/$column
 * pairs, enumerated. No dedicated Integration/Browser spec of its own.
 */
test('tableAndColumn maps every case to its real table/column pair', function (): void {
    expect(CategoryOrphanTarget::ImageCategory->tableAndColumn()->table)->toBe('image_category')
        ->and(CategoryOrphanTarget::ImageCategory->tableAndColumn()->column)->toBe('category_id')
        ->and(CategoryOrphanTarget::UserAccess->tableAndColumn()->table)->toBe('user_access')
        ->and(CategoryOrphanTarget::UserAccess->tableAndColumn()->column)->toBe('cat_id')
        ->and(CategoryOrphanTarget::GroupAccess->tableAndColumn()->table)->toBe('group_access')
        ->and(CategoryOrphanTarget::GroupAccess->tableAndColumn()->column)->toBe('cat_id')
        ->and(CategoryOrphanTarget::OldPermalinks->tableAndColumn()->table)->toBe('old_permalinks')
        ->and(CategoryOrphanTarget::OldPermalinks->tableAndColumn()->column)->toBe('cat_id');
});

test('entityClassAndProperty maps every DQL-backed case to its real entity/property pair, and OldPermalinks to null', function (): void {
    $imageCategory = CategoryOrphanTarget::ImageCategory->entityClassAndProperty();
    expect($imageCategory)
        ->not->toBeNull();
    if ($imageCategory instanceof DqlPropertyTarget) {
        expect($imageCategory->entityClass)->toBe(ImageCategoryEntity::class)
            ->and($imageCategory->property)
            ->toBe('categoryId');
    }

    $userAccess = CategoryOrphanTarget::UserAccess->entityClassAndProperty();
    expect($userAccess)
        ->not->toBeNull();
    if ($userAccess instanceof DqlPropertyTarget) {
        expect($userAccess->entityClass)->toBe(UserAccessEntity::class)
            ->and($userAccess->property)
            ->toBe('catId');
    }

    $groupAccess = CategoryOrphanTarget::GroupAccess->entityClassAndProperty();
    expect($groupAccess)
        ->not->toBeNull();
    if ($groupAccess instanceof DqlPropertyTarget) {
        expect($groupAccess->entityClass)->toBe(GroupAccessEntity::class)
            ->and($groupAccess->property)
            ->toBe('catId');
    }

    expect(CategoryOrphanTarget::OldPermalinks->entityClassAndProperty())->toBeNull();
});
