<?php

declare(strict_types=1);

use Piwigo\Category\CategoryOrphanTarget;
use Piwigo\Category\UserAccessEntity;
use Piwigo\Group\GroupAccessEntity;
use Piwigo\Image\ImageCategoryEntity;

/**
 * Piwigo\Category\CategoryOrphanTarget -- CategoryRepository::
 * findOrphanedColumnValues()/deleteRowsWhereColumnIn()'s entity/property
 * pairs, enumerated. No dedicated Integration/Browser spec of its own.
 */
test('entityClassAndProperty maps every case to its real entity/property pair', function (): void {
    $imageCategory = CategoryOrphanTarget::ImageCategory->entityClassAndProperty();
    expect($imageCategory->entityClass)
        ->toBe(ImageCategoryEntity::class)
        ->and($imageCategory->property)
        ->toBe('category')
        ->and($imageCategory->isAssociation)
        ->toBeTrue();

    $userAccess = CategoryOrphanTarget::UserAccess->entityClassAndProperty();
    expect($userAccess->entityClass)
        ->toBe(UserAccessEntity::class)
        ->and($userAccess->property)
        ->toBe('catId')
        ->and($userAccess->isAssociation)
        ->toBeFalse();

    $groupAccess = CategoryOrphanTarget::GroupAccess->entityClassAndProperty();
    expect($groupAccess->entityClass)
        ->toBe(GroupAccessEntity::class)
        ->and($groupAccess->property)
        ->toBe('catId')
        ->and($groupAccess->isAssociation)
        ->toBeFalse();
});
