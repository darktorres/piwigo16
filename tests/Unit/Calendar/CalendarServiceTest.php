<?php

declare(strict_types=1);

use Piwigo\Calendar\CalendarService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\FilterState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Every repository below is only ever constructed here, never queried --
 * buildInnerSql() only reaches a DB-bound call
 * (CategoryService::getSubcatIds()) via its hasCategoryContext=true +
 * $categoryId!==null branch, which stays untested here and is covered at
 * Integration level instead (CalendarServiceTest.php there). Every other
 * branch is a pure string builder (see PermissionServiceTest.php's own
 * getSqlConditionFandF() coverage, buildInnerSql()'s real dependency for
 * the "browse everything visible" branch).
 */
function makeCalendarService(): CalendarService
{
    $conn = DbConnection::build();
    $permissionService = new PermissionService(
        new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
        \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class),
        \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
    );

    return new CalendarService(
        $permissionService,
        new CategoryService(\Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class), $permissionService),
    );
}

beforeEach(function (): void {
    CurrentUser::set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    CurrentUser::reset();
    FilterState::reset();
});

test('buildInnerSql returns null for a non-category section with no items', function (): void {
    $service = makeCalendarService();

    expect($service->buildInnerSql('tags', false, null, '', []))->toBeNull();
});

test('buildInnerSql builds a WHERE id IN clause for a non-category section', function (): void {
    $service = makeCalendarService();

    $sql = $service->buildInnerSql('tags', false, null, '', [1, 2, 3]);

    expect($sql)->toBe(' FROM ' . Tables::images() . "\nWHERE id IN (1,2,3)");
});

test('buildInnerSql returns null when there is a category context but no resolved category id', function (): void {
    // $categoryId === null short-circuits before CategoryService::getSubcatIds()
    // (a DB-bound call) is ever reached -- $subIds stays [], which is the
    // same "nothing to do" early return as an empty sub-category set.
    $service = makeCalendarService();

    expect($service->buildInnerSql('categories', true, null, '', []))->toBeNull();
});

test('buildInnerSql browses everything visible when there is no category context', function (): void {
    $service = makeCalendarService();

    $sql = $service->buildInnerSql('categories', false, null, '', []);

    // Even a fully-default CurrentUser still gets a "level<=0" clause --
    // visible_images falls through into the forbidden_images level check
    // unconditionally unless image_access_type is exactly 'NOT IN' (see
    // PermissionServiceTest.php's own fallthrough coverage).
    expect($sql)->toBe(
        ' FROM ' . Tables::images()
        . "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id'
        . "\n    WHERE (level<=0)"
    );
});

test('buildInnerSql composes forbidden/visible categories and images into the WHERE clause', function (): void {
    CurrentUser::set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
        forbiddenCategories: '5,6',
        level: 2,
    ));
    FilterState::set(true, visibleCategories: '10,20', visibleImages: '100,200');
    $service = makeCalendarService();

    $sql = $service->buildInnerSql('categories', false, null, '', []);

    expect($sql)->toBe(
        ' FROM ' . Tables::images()
        . "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id'
        . "\n    WHERE (category_id NOT IN (5,6) AND category_id IN (10,20) AND id IN (100,200) AND level<=2)"
    );
});
