<?php

declare(strict_types=1);

use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * PermissionRepository/GroupRepository/CategoryRepository are only ever
 * constructed here, never queried -- getSqlConditionFandF()/
 * getPrivacyLevelOptions() are pure string builders reading CurrentUser/
 * FilterState/CurrentConfig directly (see the class's own docblock), same
 * "real repository, DB never touched by the tested method" shape as
 * tests/Unit/Image/ImageServiceTest.php.
 */
function makePermissionService(): PermissionService
{
    $conn = DbConnection::build();

    return new PermissionService(
        new PermissionRepository($conn),
        new GroupRepository($conn),
        new CategoryRepository($conn),
    );
}

function seedPermissionUser(string $forbiddenCategories = '', int $level = 0, string $imageAccessType = '', string $imageAccessList = ''): void
{
    CurrentUser::set(new User(
        id: 1,
        username: 'torres',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
        forbiddenCategories: $forbiddenCategories,
        level: $level,
        rawAttributes: [
            'image_access_type' => $imageAccessType,
            'image_access_list' => $imageAccessList,
        ],
    ));
}

beforeEach(function (): void {
    seedPermissionUser();
});

afterEach(function (): void {
    CurrentUser::reset();
    FilterState::reset();
    CurrentConfig::reset();
});

test('getSqlConditionFandF returns an empty string when nothing applies', function (): void {
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['forbidden_categories' => 'category_id']);

    expect($sql)->toBe('');
});

test('getSqlConditionFandF forces a tautology when forceOneCondition is set and nothing applies', function (): void {
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['forbidden_categories' => 'category_id'], null, true);

    expect($sql)->toBe('1 = 1');
});

test('getSqlConditionFandF builds a NOT IN clause from the user forbidden categories', function (): void {
    seedPermissionUser(forbiddenCategories: '2,3');
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['forbidden_categories' => 'category_id']);

    expect($sql)->toBe('(category_id NOT IN (2,3))');
});

test('getSqlConditionFandF builds an IN clause from FilterState visible categories/images', function (): void {
    // image_access_type: 'NOT IN' with an empty list is the "no restriction"
    // default (see getuserdata()) -- seeded here purely to suppress
    // visible_images' own fallthrough into the forbidden_images level
    // check below, which is exercised on its own further down.
    seedPermissionUser(imageAccessType: 'NOT IN');
    FilterState::set(true, visibleCategories: '1,2', visibleImages: '10,11');
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF([
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ]);

    expect($sql)->toBe('(category_id IN (1,2) AND id IN (10,11))');
});

test('getSqlConditionFandF prepends the prefix only when the built condition is non-empty', function (): void {
    seedPermissionUser(forbiddenCategories: '5');
    $service = makePermissionService();

    $withCondition = $service->getSqlConditionFandF(['forbidden_categories' => 'category_id'], "\n  AND");
    $withoutCondition = $service->getSqlConditionFandF(['visible_categories' => 'category_id'], "\n  AND");

    expect($withCondition)->toBe("\n  AND (category_id NOT IN (5))")
        ->and($withoutCondition)->toBe('');
});

test('getSqlConditionFandF falls through from visible_images into the level check', function (): void {
    // No break after 'visible_images' -- "visible include forbidden", per
    // the method's own comment. Default image_access_type ('') !== 'NOT IN'
    // so the fallthrough's own condition is true here.
    seedPermissionUser(level: 3);
    FilterState::set(true, visibleImages: '10,11');
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['visible_images' => 'id']);

    expect($sql)->toBe('(id IN (10,11) AND level<=3)');
});

test('getSqlConditionFandF forbidden_images prefixes the level check for the i.id field', function (): void {
    seedPermissionUser(level: 5);

    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['forbidden_images' => 'i.id']);

    expect($sql)->toBe('(i.level<=5)');
});

test('getSqlConditionFandF forbidden_images uses the raw access-list clause for a non-id field', function (): void {
    seedPermissionUser(imageAccessType: 'NOT IN', imageAccessList: '7,8');
    $service = makePermissionService();

    $sql = $service->getSqlConditionFandF(['forbidden_images' => 'category_id']);

    expect($sql)->toBe('(category_id NOT IN (7,8))');
});

test('getSqlConditionFandF throws for an unknown condition key', function (): void {
    $service = makePermissionService();

    $service->getSqlConditionFandF(['not_a_real_condition' => 'x']);
})->throws(InvalidArgumentException::class, 'Unknown condition: not_a_real_condition');

test('getPrivacyLevelOptions labels level 0 as Everybody and stacks the rest', function (): void {
    $options = PermissionService::getPrivacyLevelOptions();

    // The shared $label accumulator walks levels high-to-low and keeps
    // growing until it hits 0, which resets it to "Everybody" instead of
    // appending -- so lower levels list every higher level before them.
    expect($options)->toBe([
        8 => 'Level 8',
        4 => 'Level 8, Level 4',
        2 => 'Level 8, Level 4, Level 2',
        1 => 'Level 8, Level 4, Level 2, Level 1',
        0 => 'Everybody',
    ]);
});

test('getPrivacyLevelOptions follows CurrentConfig::availablePermissionLevels when overridden', function (): void {
    CurrentConfig::setAvailablePermissionLevels([0, 5]);

    $options = PermissionService::getPrivacyLevelOptions();

    expect($options)->toBe([
        5 => 'Level 5',
        0 => 'Everybody',
    ]);
});
