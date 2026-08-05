<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Calendar\CalendarService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
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
 *
 * A Unit-scoped mutation-testing sweep (--testsuite Unit) flags the
 * array_diff()/getSubcatIds([$categoryId]) line inside that same DB-bound
 * branch as untested -- a genuine suite-scope mismatch, not a real gap:
 * tests/Integration/CalendarServiceTest.php's own
 * test_build_inner_sql_excludes_forbidden_subcategories already exercises
 * a real array_diff() exclusion (forbidding category 2 removes it from
 * the subcategory set without touching category 1), and
 * test_build_inner_sql_for_a_specific_category exercises the
 * [$categoryId] wrapping array with a real, non-empty result. Not chased
 * here for the same reason BackupService's own DB/subprocess-bound gaps
 * weren't -- already thorough at the suite that can actually reach it.
 */
function makeCalendarService(): CalendarService
{
    $conn = DbConnection::build();
    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new \LogicException('Container returned an unexpected type for ' . FilterState::class);
    }
    $permissionService = new PermissionService(
        new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
        \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class),
        new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($conn), \Piwigo\Config\CurrentConfig::current()),
        \Piwigo\Users\CurrentUser::current(),
        $filterState,
    );

    return new CalendarService(
        $permissionService,
        new CategoryService(Lang::current(), new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build($conn), \Piwigo\Config\CurrentConfig::current()), $permissionService, \Piwigo\Config\CurrentConfig::current(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Lang\Translator::get()),
    );
}

/**
 * PermissionService::getPermissionCriteria() (buildInnerSql()'s own
 * dependency) reads FilterState via real constructor injection
 * (singleton/service-locator elimination campaign, Phase 11 sub-phase
 * 11G) -- this helper seeds the real container-shared instance directly
 * so the same object makeCalendarService() constructed with observes the
 * seeded state.
 */
function seedCalendarFilterState(bool $enabled, string $visibleCategories = '', string $visibleImages = ''): void
{
    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new \LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    $filterState->set($enabled, $visibleCategories, $visibleImages);
}

beforeEach(function (): void {
    // A bare Kernel::boot() (no Paths bound) used to be enough here -- none
    // of PermissionService/CategoryService's own dependencies needed one.
    // CategoryService now takes Lang via constructor injection (singleton/
    // service-locator elimination campaign, Phase 8), and Lang's own
    // container resolution requires a real, bound Paths::class -- so a real
    // (if never actually read from disk here) root is needed for the
    // container to resolve it at all, same recipe as UploadServiceTest's own
    // beforeEach()'s Kernel::boot(Paths::fromRoot(...)) call.
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentUser::current()->set(new User(
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
    CurrentUser::current()->reset();
    Kernel::reset();
});

test('buildInnerSql returns null for a non-category section with no items', function (): void {
    $service = makeCalendarService();

    expect($service->buildInnerSql('tags', false, null, '', []))->toBeNull();
});

test('buildInnerSql builds a WHERE id IN clause for a non-category section', function (): void {
    $service = makeCalendarService();

    $scope = $service->buildInnerSql('tags', false, null, '', [1, 2, 3]);
    assert($scope !== null);

    expect($scope->rawSqlFromWhere->sql)->toBe(' FROM ' . Tables::images() . "\nWHERE id IN (:innerItems)")
        ->and($scope->rawSqlFromWhere->parameters)->toBe(['innerItems' => ['1', '2', '3']])
        ->and($scope->rawSqlFromWhere->types)->toBe(['innerItems' => ArrayParameterType::STRING])
        ->and($scope->joinImageCategory)->toBeFalse()
        ->and($scope->dqlWhere->sql)->toBe('i.id IN (:innerItems)')
        ->and($scope->dqlWhere->parameters)->toBe(['innerItems' => [1, 2, 3]]);
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

    $scope = $service->buildInnerSql('categories', false, null, '', []);
    assert($scope !== null);

    // Even a fully-default CurrentUser still gets a "level <= 0" clause --
    // visible_images falls through into the forbidden_images level check
    // unconditionally unless image_access_type is exactly 'NOT IN' (see
    // PermissionServiceTest.php's own fallthrough coverage). The
    // placeholder name is a monotonic per-process counter (see
    // PermissionServiceTest.php's own singleParamKey() convention), so
    // extract whatever name was actually generated.
    $key = array_key_first($scope->rawSqlFromWhere->parameters);
    expect($key)->toBeString();
    assert(is_string($key));

    expect($scope->rawSqlFromWhere->sql)->toBe(
        ' FROM ' . Tables::images()
        . "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id'
        . "\n    WHERE level <= :{$key}"
    )->and($scope->rawSqlFromWhere->parameters)->toBe([$key => 0]);

    expect($scope->joinImageCategory)->toBeTrue();
    $dqlKey = array_key_first($scope->dqlWhere->parameters);
    expect($dqlKey)->toBeString();
    assert(is_string($dqlKey));
    expect($scope->dqlWhere->sql)->toBe("i.level <= :{$dqlKey}")
        ->and($scope->dqlWhere->parameters)->toBe([$dqlKey => 0]);
});

test('buildInnerSql falls back to a forced 1 = 1 condition when no permission clause applies at all', function (): void {
    // getSqlConditionFandF()'s own $forceOneCondition=true (this call's
    // literal third argument) is only ever consulted when every one of
    // the 3 requested conditions comes back empty -- both existing
    // "browse everything visible" tests above always produce at least a
    // level<=X clause (image_access_type !== 'NOT IN' by default), so
    // neither can distinguish forceOneCondition=true from a mutated
    // false. A user with no forbidden categories, no FilterState
    // restriction, and image_access_type exactly 'NOT IN' with an empty
    // image_access_list empties every one of the 3 conditions passed
    // here (see PermissionService::getSqlConditionFandF()'s own
    // visible_images/forbidden_images fallthrough case).
    CurrentUser::current()->set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: ['image_access_type' => 'NOT IN', 'image_access_list' => ''],
    ));
    $service = makeCalendarService();

    $scope = $service->buildInnerSql('categories', false, null, '', []);
    assert($scope !== null);

    expect($scope->rawSqlFromWhere->sql)->toBe(
        ' FROM ' . Tables::images()
        . "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id'
        . "\n    WHERE 1 = 1"
    )->and($scope->rawSqlFromWhere->parameters)->toBe([]);

    // No '1 = 1' fallback needed on the DQL side -- CalendarRepository's
    // own applyCondition() helper skips an empty SqlCondition entirely
    // (no andWhere() call at all) rather than requiring non-empty text.
    expect($scope->dqlWhere->isEmpty())->toBeTrue();
});

test('buildInnerSql composes forbidden/visible categories and images into the WHERE clause', function (): void {
    CurrentUser::current()->set(new User(
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
    seedCalendarFilterState(true, visibleCategories: '10,20', visibleImages: '100,200');
    $service = makeCalendarService();

    $scope = $service->buildInnerSql('categories', false, null, '', []);
    assert($scope !== null);

    expect($scope->rawSqlFromWhere->parameters)->toHaveCount(4);
    [$forbidKey, $visCatKey, $visImgKey, $levelKey] = array_keys($scope->rawSqlFromWhere->parameters);

    expect($scope->rawSqlFromWhere->sql)->toBe(
        ' FROM ' . Tables::images()
        . "\nINNER JOIN " . Tables::imageCategory() . ' ON id = image_id'
        . "\n    WHERE category_id NOT IN (:{$forbidKey}) AND category_id IN (:{$visCatKey}) AND id IN (:{$visImgKey}) AND level <= :{$levelKey}"
    )->and($scope->rawSqlFromWhere->parameters)->toBe([
        $forbidKey => [5, 6],
        $visCatKey => [10, 20],
        $visImgKey => [100, 200],
        $levelKey => 2,
    ]);

    expect($scope->dqlWhere->parameters)->toHaveCount(4);
    [$dqlForbidKey, $dqlVisCatKey, $dqlVisImgKey, $dqlLevelKey] = array_keys($scope->dqlWhere->parameters);

    expect($scope->dqlWhere->sql)->toBe(
        "ic.categoryId NOT IN (:{$dqlForbidKey}) AND ic.categoryId IN (:{$dqlVisCatKey}) AND i.id IN (:{$dqlVisImgKey}) AND i.level <= :{$dqlLevelKey}"
    )->and($scope->dqlWhere->parameters)->toBe([
        $dqlForbidKey => [5, 6],
        $dqlVisCatKey => [10, 20],
        $dqlVisImgKey => [100, 200],
        $dqlLevelKey => 2,
    ]);
});
