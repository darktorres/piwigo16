<?php

declare(strict_types=1);

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * PermissionRepository/GroupRepository/CategoryRepository are only ever
 * constructed here, never queried -- getSqlConditionFandFAsCondition()/
 * getPrivacyLevelOptions() are pure string/params builders reading
 * CurrentUser/FilterState/CurrentConfig directly (see the class's own
 * docblock), same "real repository, DB never touched by the tested
 * method" shape as tests/Unit/Image/ImageServiceTest.php.
 */
function makePermissionService(): PermissionService
{
    $conn = DbConnection::build();

    return new PermissionService(
        new PermissionRepository(\Piwigo\Db\EntityManagerFactory::build($conn)),
        \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class),
        \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class),
    );
}

/**
 * getSqlConditionFandFAsCondition() reads FilterState through the
 * `isInitializedStatic()`/`visibleCategoriesStatic()`/`visibleImagesStatic()`
 * transitional shims (singleton/service-locator elimination campaign,
 * Phase 2 -- see FilterState::isInitializedStatic()'s own docblock), which
 * resolve the real container-shared instance once Kernel::boot() has run.
 */
function seedFilterState(bool $enabled, string $visibleCategories = '', string $visibleImages = ''): void
{
    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new \LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    $filterState->set($enabled, $visibleCategories, $visibleImages);
}

function seedPermissionUser(string $forbiddenCategories = '', int $level = 0, string $imageAccessType = '', string $imageAccessList = ''): void
{
    CurrentUser::current()->set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
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

/**
 * Bypasses seedPermissionUser()'s string-only params to reach
 * getSqlConditionFandFAsCondition()'s own is_scalar()-then-(string)-cast
 * defensive guard with a rawAttributes shape that guard actually
 * exists for: `rawAttributes` is `array<string, mixed>` (see
 * `User::fromUserArray()`, which assigns the raw DB row wholesale) --
 * a driver returning a non-string scalar for a narrow column (e.g. a
 * TINYINT surfaced as a real PHP bool) or the key being genuinely absent
 * from the row are both real shapes, not just synthetic mutation-testing
 * inputs.
 *
 * @param array<string, mixed> $rawAttributes
 */
function seedPermissionUserRaw(array $rawAttributes): void
{
    CurrentUser::current()->set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from(1),
        username: 'torres',
        email: '',
        language: '',
        theme: '',
        status: UserStatus::Normal,
        enabledHigh: false,
        rawAttributes: $rawAttributes,
    ));
}

beforeEach(function (): void {
    // A real Paths is required now: PermissionService::getPrivacyLevelOptions()
    // reads Lang::current() (singleton/service-locator elimination
    // campaign, Phase 8), and Lang's own Paths constructor collaborator
    // has no autowireable default -- a bare Kernel::boot() with no Paths
    // argument leaves it unresolvable in the container.
    Kernel::boot(\Piwigo\Core\Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    seedPermissionUser();
});

afterEach(function (): void {
    CurrentUser::current()->reset();
    \Piwigo\Config\CurrentConfig::current()->reset();
    Lang::current()->reset();
    Translator::get()->reset();
    Kernel::reset();
});

/**
 * The one real placeholder name out of a single-condition SqlCondition --
 * getSqlConditionFandFAsCondition()'s suffix is a monotonic per-process
 * counter (see PermissionService::nextPlaceholderSuffix()), so tests can't
 * assert a fixed name; they extract whatever name was actually generated
 * instead.
 */
function singleParamKey(\Piwigo\Permission\SqlCondition $condition): string
{
    $key = array_key_first($condition->parameters);
    expect($key)->toBeString();
    assert(is_string($key));

    return $key;
}

test('getSqlConditionFandFAsCondition returns an empty condition when nothing applies', function (): void {
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_categories' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition forces a tautology when forceOneCondition is set and nothing applies', function (): void {
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_categories' => 'category_id'], true);

    expect($condition->sql)->toBe('1 = 1')
        ->and($condition->parameters)->toBe([]);
});

test('getSqlConditionFandFAsCondition builds a bound NOT IN clause from the user forbidden categories', function (): void {
    seedPermissionUser(forbiddenCategories: '2,3');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_categories' => 'category_id']);
    $key = singleParamKey($condition);

    expect($condition->sql)->toBe('(category_id NOT IN (:' . $key . '))')
        ->and($condition->parameters)->toBe([$key => [2, 3]])
        ->and($condition->types)->toBe([$key => ArrayParameterType::INTEGER]);
});

test('getSqlConditionFandFAsCondition builds bound IN clauses from FilterState visible categories/images', function (): void {
    seedPermissionUser(imageAccessType: 'NOT IN');
    seedFilterState(true, visibleCategories: '1,2', visibleImages: '10,11');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition([
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ]);

    expect($condition->parameters)->toHaveCount(2);
    [$catsKey, $imagesKey] = array_keys($condition->parameters);

    expect($condition->sql)->toBe('(category_id IN (:' . $catsKey . ') AND id IN (:' . $imagesKey . '))')
        ->and($condition->parameters)->toBe([$catsKey => [1, 2], $imagesKey => [10, 11]])
        ->and($condition->types)->toBe([
            $catsKey => ArrayParameterType::INTEGER,
            $imagesKey => ArrayParameterType::INTEGER,
        ]);
});

test('getSqlConditionFandFAsCondition falls through from visible_images into the bound level check', function (): void {
    seedPermissionUser(level: 3);
    seedFilterState(true, visibleImages: '10,11');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['visible_images' => 'id']);

    expect($condition->parameters)->toHaveCount(2);
    [$imagesKey, $levelKey] = array_keys($condition->parameters);

    expect($condition->sql)->toBe('(id IN (:' . $imagesKey . ') AND level<=:' . $levelKey . ')')
        ->and($condition->parameters[$levelKey])->toBe(3)
        ->and($condition->types[$levelKey])->toBe(ParameterType::INTEGER);
});

test('getSqlConditionFandFAsCondition forbidden_images binds the level check for the i.id field', function (): void {
    seedPermissionUser(level: 5);
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'i.id']);
    $key = singleParamKey($condition);

    expect($condition->sql)->toBe('(i.level<=:' . $key . ')')
        ->and($condition->parameters)->toBe([$key => 5])
        ->and($condition->types)->toBe([$key => ParameterType::INTEGER]);
});

test('getSqlConditionFandFAsCondition forbidden_images binds the raw access-list clause for a non-id field', function (): void {
    seedPermissionUser(imageAccessType: 'NOT IN', imageAccessList: '7,8');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);
    $key = singleParamKey($condition);

    expect($condition->sql)->toBe('(category_id NOT IN (:' . $key . '))')
        ->and($condition->parameters)->toBe([$key => [7, 8]])
        ->and($condition->types)->toBe([$key => ArrayParameterType::INTEGER]);
});

test('getSqlConditionFandFAsCondition uses a distinct placeholder per call, not a fixed name', function (): void {
    seedPermissionUser(forbiddenCategories: '9');
    $service = makePermissionService();

    $first = $service->getSqlConditionFandFAsCondition(['forbidden_categories' => 'category_id']);
    $second = $service->getSqlConditionFandFAsCondition(['forbidden_categories' => 'category_id']);

    expect(array_key_first($first->parameters))->not->toBe(array_key_first($second->parameters));
});

test('getSqlConditionFandFAsCondition throws for an unknown condition key', function (): void {
    $service = makePermissionService();

    $service->getSqlConditionFandFAsCondition(['not_a_real_condition' => 'x']);
})->throws(InvalidArgumentException::class, 'Unknown condition: not_a_real_condition');

test('getSqlConditionFandFAsCondition treats a non-string scalar image_access_type as empty, not as a truthy bypass', function (): void {
    // rawAttributes['image_access_type'] as a real PHP bool (not a string) is
    // exactly what the is_scalar()-then-(string)-cast guard on the method's
    // own image_access_type read exists for. The cast collapses `false` to
    // '' before the elseif's own `$userImageAccessType !== ''` check --
    // without the cast, `false !== ''` is true (mismatched types are never
    // `===`), which would wrongly let a malformed "field  (list)" clause (no
    // operator between field and list) through even though there is no real
    // access-type operator to use.
    seedPermissionUserRaw(['image_access_type' => false, 'image_access_list' => '7,8']);
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition treats a genuinely absent image_access_type as empty', function (): void {
    // No 'image_access_type' key at all -- the `?? null` fallback then
    // is_scalar(null) === false, landing in the ternary's '' branch (not the
    // (string)-cast branch above, so this exercises the *fallback literal*
    // rather than the cast). Same expected result as the bool-false case: an
    // absent/non-scalar access type must not enable the raw access-list
    // clause below.
    seedPermissionUserRaw(['image_access_list' => '7,8']);
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition treats a non-string scalar image_access_list as empty, not as a truthy bypass', function (): void {
    // Mirror of the image_access_type case above, for the method's own
    // is_scalar()-then-(string)-cast guard on image_access_list.
    // image_access_type is set to a real, distinct-from-'NOT IN' value
    // ('IN') purely so the type-side gate is satisfied, isolating the list
    // side's own cast for this assertion.
    seedPermissionUserRaw(['image_access_type' => 'IN', 'image_access_list' => false]);
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition treats a genuinely absent image_access_list as empty', function (): void {
    // No 'image_access_list' key at all -- exercises the fallback ''
    // literal (not its cast) the same way the image_access_type test above
    // exercises its own.
    seedPermissionUserRaw(['image_access_type' => 'IN']);
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition requires both a non-empty access list AND a non-empty access type for the raw clause', function (): void {
    // image_access_type is non-empty ('IN', deliberately not 'NOT IN' so the
    // type-side gate is satisfied) but image_access_list stays '' (default)
    // -- the elseif's `&&` must reject this: an access *type* with no list
    // to pair it with is exactly as unusable as a list with no type (the
    // test below). Also pins the elseif's own '' literal on the list side:
    // nothing about "list is empty" changes if that literal were some other
    // string, since '' really is what $userImageAccessList holds here.
    seedPermissionUser(imageAccessType: 'IN');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition requires both a non-empty access list AND a non-empty access type, not just a list', function (): void {
    // Flip side of the test above: a non-empty access list ('7,8') with
    // image_access_type left at its '' default. Pins the elseif's `&&` (an
    // `||` mutant would wrongly build "category_id  (7,8)", a malformed
    // clause with no operator) and the elseif's own '' literal on the type
    // side.
    seedPermissionUser(imageAccessList: '7,8');
    $service = makePermissionService();

    $condition = $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);

    expect($condition->sql)->toBe('')
        ->and($condition->parameters)->toBe([])
        ->and($condition->types)->toBe([]);
});

test('getSqlConditionFandFAsCondition throws for a corrupted image_access_type', function (): void {
    seedPermissionUser(imageAccessType: 'bogus', imageAccessList: '1');
    $service = makePermissionService();

    $service->getSqlConditionFandFAsCondition(['forbidden_images' => 'category_id']);
})->throws(UnexpectedValueException::class, 'Unexpected image_access_type: bogus');

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
    CurrentConfig::current()->setAvailablePermissionLevels([0, 5]);

    $options = PermissionService::getPrivacyLevelOptions();

    expect($options)->toBe([
        5 => 'Level 5',
        0 => 'Everybody',
    ]);
});

test('getPrivacyLevelOptions does not prepend a stray separator before the very first stacked label', function (): void {
    // `if (strlen($label) > 0)` guards the ", " separator prepended before
    // every stacked label after the first. Every real Lang::t() output
    // ("Everybody"/"Level N") is always more than 1 character long, so no
    // untranslated wording could ever land exactly on the `> 0` vs `> 1`
    // boundary -- only a translation override that shrinks a label down to
    // exactly 1 character can. `Lang::loadArray()` targets a translator
    // *output* key, so it maps the already-`sprintf()`-formatted 'Level 2'
    // string, not the 'Level %d' format string itself.
    CurrentConfig::current()->setAvailablePermissionLevels([0, 1, 2]);
    Lang::current()->loadArray(['Level 2' => 'X']);

    $options = PermissionService::getPrivacyLevelOptions();

    // Level 2 (processed first, array_reverse()'d) seeds $label = 'X' (1
    // char). Level 1 (processed next) must see strlen('X') === 1 > 0 and
    // prepend ", " -- an `> 1` mutant would skip it, yielding 'XLevel 1'
    // instead.
    expect($options[1])->toBe('X, Level 1');
});
