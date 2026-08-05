<?php

declare(strict_types=1);

use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\Paths;
use Piwigo\Permission\SqlCondition;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Db\DbConnection;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * PermissionRepository/GroupRepository/CategoryRepository are only ever
 * constructed here, never queried -- getPermissionCriteria()/
 * getPrivacyLevelOptions() are pure string/params builders reading
 * CurrentUser/FilterState/CurrentConfig directly (see the class's own
 * docblock), same "real repository, DB never touched by the tested
 * method" shape as tests/Unit/Image/ImageServiceTest.php.
 */
function makePermissionService(): PermissionService
{
    $conn = DbConnection::build();

    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    return new PermissionService(
        new PermissionRepository(EntityManagerFactory::build($conn)),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build($conn), CurrentConfig::current()),
        CurrentUser::current(),
        $filterState,
    );
}

/**
 * getPermissionCriteria() reads FilterState via real constructor injection
 * (singleton/service-locator elimination campaign, Phase 11 sub-phase
 * 11G) -- this helper seeds the real container-shared instance directly
 * so the same object PermissionService was constructed with observes the
 * seeded state.
 */
function seedFilterState(bool $enabled, string $visibleCategories = '', string $visibleImages = ''): void
{
    $filterState = Kernel::container()->get(FilterState::class);
    if (! $filterState instanceof FilterState) {
        throw new LogicException('Container returned an unexpected type for ' . FilterState::class);
    }

    $filterState->set($enabled, $visibleCategories, $visibleImages);
}

function seedPermissionUser(string $forbiddenCategories = '', int $level = 0, string $imageAccessType = '', string $imageAccessList = ''): void
{
    CurrentUser::current()->set(new User(
        id: UserId::from(1),
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
        id: UserId::from(1),
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
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3) . '/'));
    seedPermissionUser();
});

afterEach(function (): void {
    CurrentUser::current()->reset();
    CurrentConfig::current()->reset();
    Lang::current()->reset();
    Translator::get()->reset();
    Kernel::reset();
});

test('getPermissionCriteria returns every field null when nothing applies', function (): void {
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->forbiddenCategoryIds)->toBeNull()
        ->and($criteria->visibleCategoryIds)->toBeNull()
        ->and($criteria->visibleImageIds)->toBeNull()
        // level=0 (seedPermissionUser()'s own default) with image_access_type
        // '' (not 'NOT IN') satisfies $forbiddenImagesApplies, so maxLevel is
        // 0, not null -- pins the "applies" gate independently of the
        // access-list/type pair below.
        ->and($criteria->maxLevel)->toBe(0)
        ->and($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();

    // Every *Condition() builder must return an empty fragment for a null
    // field, so callers can combine them unconditionally.
    expect($criteria->forbiddenCategoriesCondition('category_id')->isEmpty())->toBeTrue()
        ->and($criteria->visibleCategoriesCondition('category_id')->isEmpty())->toBeTrue()
        ->and($criteria->visibleImagesCondition('id')->isEmpty())->toBeTrue()
        ->and($criteria->imageAccessCondition('category_id')->isEmpty())->toBeTrue();
});

test('getPermissionCriteria computes forbiddenCategoryIds from the user forbidden categories, bound via forbiddenCategoriesCondition', function (): void {
    seedPermissionUser(forbiddenCategories: '2,3');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->forbiddenCategoryIds)->toBe([2, 3]);

    $condition = $criteria->forbiddenCategoriesCondition('category_id');

    expect($condition->sql)->toBe('category_id NOT IN (:permForbiddenCategoryIds)')
        ->and($condition->parameters)->toBe(['permForbiddenCategoryIds' => [2, 3]])
        ->and($condition->types)->toBe(['permForbiddenCategoryIds' => ArrayParameterType::INTEGER]);
});

test('getPermissionCriteria computes visibleCategoryIds/visibleImageIds from FilterState, each bound via its own *Condition builder', function (): void {
    seedPermissionUser(imageAccessType: 'NOT IN');
    seedFilterState(true, visibleCategories: '1,2', visibleImages: '10,11');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->visibleCategoryIds)->toBe([1, 2])
        ->and($criteria->visibleImageIds)->toBe([10, 11]);

    $catCondition = $criteria->visibleCategoriesCondition('category_id');
    $imgCondition = $criteria->visibleImagesCondition('id');

    expect($catCondition->sql)->toBe('category_id IN (:permVisibleCategoryIds)')
        ->and($catCondition->parameters)->toBe(['permVisibleCategoryIds' => [1, 2]])
        ->and($imgCondition->sql)->toBe('id IN (:permVisibleImageIds)')
        ->and($imgCondition->parameters)->toBe(['permVisibleImageIds' => [10, 11]]);
});

test('getPermissionCriteria always computes maxLevel alongside visibleImageIds, not one gating the other', function (): void {
    // The old getSqlConditionFandFAsCondition()'s own `visible_images` case
    // fell through into `forbidden_images` with no `break` -- callers that
    // requested visibleImageIds always implicitly got the level check too.
    // The typed replacement computes both fields unconditionally instead
    // (see PermissionCriteria's own docblock); this pins that both are
    // populated together whenever the "applies" gate is satisfied, matching
    // the old fallthrough's real net effect.
    seedPermissionUser(level: 3);
    seedFilterState(true, visibleImages: '10,11');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->visibleImageIds)->toBe([10, 11])
        ->and($criteria->maxLevel)->toBe(3);

    $levelCondition = $criteria->maxLevelCondition('level');

    expect($levelCondition->sql)->toBe('level <= :permMaxLevel')
        ->and($levelCondition->parameters)->toBe(['permMaxLevel' => 3])
        ->and($levelCondition->types)->toBe(['permMaxLevel' => ParameterType::INTEGER]);
});

test('getPermissionCriteria computes maxLevel from the user level, bound via maxLevelCondition', function (): void {
    seedPermissionUser(level: 5);
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->maxLevel)->toBe(5);

    $condition = $criteria->maxLevelCondition('i.level');

    expect($condition->sql)->toBe('i.level <= :permMaxLevel')
        ->and($condition->parameters)->toBe(['permMaxLevel' => 5])
        ->and($condition->types)->toBe(['permMaxLevel' => ParameterType::INTEGER]);
});

test('getPermissionCriteria computes imageAccessIds/imageAccessIsAllowlist from the user access list, bound via imageAccessCondition', function (): void {
    seedPermissionUser(imageAccessType: 'NOT IN', imageAccessList: '7,8');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBe([7, 8])
        ->and($criteria->imageAccessIsAllowlist)->toBeFalse();

    $condition = $criteria->imageAccessCondition('category_id');

    expect($condition->sql)->toBe('category_id NOT IN (:permImageAccessIds)')
        ->and($condition->parameters)->toBe(['permImageAccessIds' => [7, 8]])
        ->and($condition->types)->toBe(['permImageAccessIds' => ArrayParameterType::INTEGER]);
});

test('imageAccessCondition builds an IN clause, not NOT IN, when imageAccessIsAllowlist is true', function (): void {
    seedPermissionUser(imageAccessType: 'IN', imageAccessList: '7,8');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIsAllowlist)->toBeTrue();

    $condition = $criteria->imageAccessCondition('category_id');

    expect($condition->sql)->toBe('category_id IN (:permImageAccessIds)');
});

test('every *Condition builder uses a distinct placeholder name, so combining several in one query never collides', function (): void {
    seedPermissionUser(forbiddenCategories: '1', level: 2, imageAccessType: 'IN', imageAccessList: '3');
    seedFilterState(true, visibleCategories: '4', visibleImages: '5');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    $combined = SqlCondition::combine(
        'AND',
        $criteria->forbiddenCategoriesCondition('a'),
        $criteria->visibleCategoriesCondition('b'),
        $criteria->visibleImagesCondition('c'),
        $criteria->maxLevelCondition('d'),
        $criteria->imageAccessCondition('e'),
    );

    // 5 distinct fragments, each with its own single bound parameter -- if
    // any two shared a placeholder name, this count would be lower than 5
    // (a later setParameter()-equivalent silently overwriting an earlier
    // one in the merged array).
    expect($combined->parameters)->toHaveCount(5);
});

test('getPermissionCriteria treats a non-string scalar image_access_type as empty, not as a truthy bypass', function (): void {
    // rawAttributes['image_access_type'] as a real PHP bool (not a string) is
    // exactly what the is_scalar()-then-(string)-cast guard on the method's
    // own image_access_type read exists for. The cast collapses `false` to
    // '' before the `&&`'s own `$userImageAccessType !== ''` check --
    // without the cast, `false !== ''` is true (mismatched types are never
    // `===`), which would wrongly let a malformed "field  (list)" clause (no
    // operator between field and list) through even though there is no real
    // access-type operator to use.
    seedPermissionUserRaw(['image_access_type' => false, 'image_access_list' => '7,8']);
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria treats a genuinely absent image_access_type as empty', function (): void {
    // No 'image_access_type' key at all -- the `?? null` fallback then
    // is_scalar(null) === false, landing in the ternary's '' branch (not the
    // (string)-cast branch above, so this exercises the *fallback literal*
    // rather than the cast). Same expected result as the bool-false case: an
    // absent/non-scalar access type must not enable the raw access-list
    // clause below.
    seedPermissionUserRaw(['image_access_list' => '7,8']);
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria treats a non-string scalar image_access_list as empty, not as a truthy bypass', function (): void {
    // Mirror of the image_access_type case above, for the method's own
    // is_scalar()-then-(string)-cast guard on image_access_list.
    // image_access_type is set to a real, distinct-from-'NOT IN' value
    // ('IN') purely so the type-side gate is satisfied, isolating the list
    // side's own cast for this assertion.
    seedPermissionUserRaw(['image_access_type' => 'IN', 'image_access_list' => false]);
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria treats a genuinely absent image_access_list as empty', function (): void {
    // No 'image_access_list' key at all -- exercises the fallback ''
    // literal (not its cast) the same way the image_access_type test above
    // exercises its own.
    seedPermissionUserRaw(['image_access_type' => 'IN']);
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria requires both a non-empty access list AND a non-empty access type for imageAccessIds', function (): void {
    // image_access_type is non-empty ('IN', deliberately not 'NOT IN' so the
    // type-side gate is satisfied) but image_access_list stays '' (default)
    // -- the `&&` must reject this: an access *type* with no list to pair it
    // with is exactly as unusable as a list with no type (the test below).
    // Also pins the '' literal on the list side: nothing about "list is
    // empty" changes if that literal were some other string, since '' really
    // is what $userImageAccessList holds here.
    seedPermissionUser(imageAccessType: 'IN');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria requires both a non-empty access list AND a non-empty access type, not just a list', function (): void {
    // Flip side of the test above: a non-empty access list ('7,8') with
    // image_access_type left at its '' default. Pins the `&&` (an `||`
    // mutant would wrongly compute imageAccessIds from a list with no real
    // operator to pair it with) and the '' literal on the type side.
    seedPermissionUser(imageAccessList: '7,8');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

test('getPermissionCriteria throws for a corrupted image_access_type', function (): void {
    seedPermissionUser(imageAccessType: 'bogus', imageAccessList: '1');
    $service = makePermissionService();

    $service->getPermissionCriteria();
})->throws(UnexpectedValueException::class, 'Unexpected image_access_type: bogus');

test('getPermissionCriteria suppresses maxLevel/imageAccessIds together when access list is empty and type is NOT IN', function (): void {
    // The one real "$forbiddenImagesApplies" false case: an empty access
    // list paired with the literal type 'NOT IN' (an empty NOT IN list is
    // vacuously "everything", the original's own sentinel for "no real
    // restriction to apply"). Both maxLevel and imageAccessIds must stay
    // null here, not just imageAccessIds.
    seedPermissionUser(level: 9, imageAccessType: 'NOT IN');
    $service = makePermissionService();

    $criteria = $service->getPermissionCriteria();

    expect($criteria->maxLevel)->toBeNull()
        ->and($criteria->imageAccessIds)->toBeNull()
        ->and($criteria->imageAccessIsAllowlist)->toBeNull();
});

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
