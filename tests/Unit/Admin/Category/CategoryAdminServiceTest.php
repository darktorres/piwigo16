<?php

declare(strict_types=1);

// CategoryAdminService calls several real, stable, already-migrated free
// functions (admin/include/functions.php) that need much more bootstrap
// (DB connection, plugin-event system, $conf/$user globals) than this
// isolated Unit test wants to depend on. Same "same-namespace function
// shadowing" technique as P20's BatchUploadHandlerTest: PHP resolves an
// unqualified call from inside `namespace Piwigo\Admin\Category` against
// THIS namespace's function table first, so declaring these here
// transparently intercepts CategoryAdminService's own calls without
// touching the real global functions. Each stub just records its own
// invocation (name + args) so the tests below assert on ROUTING (which
// underlying call happens for which input), not on re-verifying the real
// free functions' own already-battle-tested behavior.
namespace Piwigo\Admin\Category {
    /**
     * @return array<int, array{fn: string, args: array<mixed>}>
     */
    function category_admin_service_test_calls(?array $push = null): array
    {
        static $calls = [];
        if ($push !== null) {
            $calls[] = $push;
        }
        return $calls;
    }

    function pwg_query(string $query): string
    {
        category_admin_service_test_calls(['fn' => 'pwg_query', 'args' => [$query]]);
        return 'fake-result';
    }

    function set_cat_visible(array $catIds, string $value): void
    {
        category_admin_service_test_calls(['fn' => 'set_cat_visible', 'args' => [$catIds, $value]]);
    }

    function set_cat_status(array $catIds, string $value): void
    {
        category_admin_service_test_calls(['fn' => 'set_cat_status', 'args' => [$catIds, $value]]);
    }

    function set_random_representant(array $catIds): void
    {
        category_admin_service_test_calls(['fn' => 'set_random_representant', 'args' => [$catIds]]);
    }

    // P23 batch 8f-4: the pwg_activity() function-shadow stub is gone --
    // setCategoryOption() now takes Piwigo\Core\ActivityLoggerInterface as
    // an explicit per-method parameter (the pwg_activity() free function
    // was deleted along with include/functions.inc.php), so the spy is a
    // real fake implementation passed through the API instead of a
    // same-namespace interception; it records into the same call recorder.

    /**
     * @return list<int|string>
     */
    function get_subcat_ids(array $ids): array
    {
        category_admin_service_test_calls(['fn' => 'get_subcat_ids', 'args' => [$ids]]);
        return $ids;
    }

    /**
     * @return list<int|string>
     */
    function get_uppercat_ids(array $ids): array
    {
        category_admin_service_test_calls(['fn' => 'get_uppercat_ids', 'args' => [$ids]]);
        return $ids;
    }

    /**
     * @return array<int|string, mixed>
     */
    function query2array(string $query, ?string $keyField = null, ?string $valueField = null): array
    {
        category_admin_service_test_calls(['fn' => 'query2array', 'args' => [$query, $keyField, $valueField]]);
        return $GLOBALS['category_admin_service_test_query2array'] ?? [];
    }

    function mass_inserts(string $table, array $fields, array $rows, array $options = []): void
    {
        category_admin_service_test_calls(['fn' => 'mass_inserts', 'args' => [$table, $fields, $rows, $options]]);
    }

    /**
     * @return array<string, mixed>|null
     */
    function get_cat_info(int $catId): ?array
    {
        category_admin_service_test_calls(['fn' => 'get_cat_info', 'args' => [$catId]]);
        return $GLOBALS['category_admin_service_test_cat_info'] ?? ['uppercats' => (string) $catId];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{error?: string, info?: string, id?: int}
     */
    function create_virtual_category(string $name, ?int $parentId = null, array $options = []): array
    {
        category_admin_service_test_calls(['fn' => 'create_virtual_category', 'args' => [$name, $parentId, $options]]);
        return $GLOBALS['category_admin_service_test_create_result'] ?? ['info' => 'Album added', 'id' => 42];
    }
}

namespace {

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Core\ActivityLoggerInterface;
use function Piwigo\Admin\Category\category_admin_service_test_calls;

/**
 * Real fake for setCategoryOption()'s per-method ActivityLoggerInterface
 * parameter (P23 batch 8f-4) -- records into the same recorder the
 * remaining function-shadow stubs use, so per-test assertions can keep
 * checking call ordering across both mechanisms.
 */
function category_admin_service_fake_activity_logger(): ActivityLoggerInterface
{
    return new class implements ActivityLoggerInterface {
        public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
        {
            category_admin_service_test_calls(['fn' => 'activityLogger.record', 'args' => [$object, $objectId, $action, $details]]);
        }
    };
}

beforeEach(function (): void {
    category_admin_service_test_calls(['fn' => '__reset__', 'args' => []]);
    // the recorder is a static array inside the stub function itself, not
    // resettable from outside -- filter '__reset__' markers out in each
    // test's own assertions instead of trying to clear it here.
    unset($GLOBALS['category_admin_service_test_query2array']);
    unset($GLOBALS['category_admin_service_test_cat_info']);
    unset($GLOBALS['category_admin_service_test_create_result']);
});

/**
 * @return list<array{fn: string, args: array<mixed>}>
 */
function category_admin_service_calls_since_last_reset(): array
{
    $all = category_admin_service_test_calls();
    $lastReset = 0;
    foreach ($all as $i => $call) {
        if ($call['fn'] === '__reset__') {
            $lastReset = $i;
        }
    }
    return array_slice($all, $lastReset + 1);
}

test('setCategoryOption comments=false runs a raw UPDATE and logs activity', function (): void {
    new CategoryAdminService()->setCategoryOption([1, 2], 'comments', false, category_admin_service_fake_activity_logger());

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls[0]['fn'])->toBe('pwg_query')
        ->and($calls[0]['args'][0])->toContain('commentable = \'false\'')
        ->and($calls[1]['fn'])->toBe('activityLogger.record')
        ->and($calls[1]['args'])->toBe(['album', [1, 2], 'edit', ['section' => 'comments', 'action' => 'falsify']]);
});

test('setCategoryOption visible=true delegates to set_cat_visible', function (): void {
    new CategoryAdminService()->setCategoryOption([5], 'visible', true, category_admin_service_fake_activity_logger());

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls[0])->toBe(['fn' => 'set_cat_visible', 'args' => [[5], 'true']]);
});

test('setCategoryOption status=false delegates to set_cat_status with private', function (): void {
    new CategoryAdminService()->setCategoryOption([7], 'status', false, category_admin_service_fake_activity_logger());

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls[0])->toBe(['fn' => 'set_cat_status', 'args' => [[7], 'private']]);
});

test('setCategoryOption representative=true delegates to set_random_representant', function (): void {
    new CategoryAdminService()->setCategoryOption([9], 'representative', true, category_admin_service_fake_activity_logger());

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls[0])->toBe(['fn' => 'set_random_representant', 'args' => [[9]]]);
});

test('setCategoryOption representative=false runs a raw NULL-clearing UPDATE', function (): void {
    new CategoryAdminService()->setCategoryOption([9], 'representative', false, category_admin_service_fake_activity_logger());

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls[0]['fn'])->toBe('pwg_query')
        ->and($calls[0]['args'][0])->toContain('representative_picture_id = NULL');
});

test('setCategoryOption with an empty id list does nothing', function (): void {
    new CategoryAdminService()->setCategoryOption([], 'comments', true, category_admin_service_fake_activity_logger());

    expect(category_admin_service_calls_since_last_reset())->toBe([]);
});

test('saveImageOrder updates the category row only when subcats is false', function (): void {
    new CategoryAdminService()->saveImageOrder(3, '`rank` ASC', false);

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['fn'])->toBe('pwg_query')
        ->and($calls[0]['args'][0])->toContain('WHERE id=3')
        ->and($calls[0]['args'][0])->toContain('\'`rank` ASC\'');
});

test('saveImageOrder with null order sets NULL and cascades to subcats', function (): void {
    $GLOBALS['category_admin_service_test_cat_info'] = ['uppercats' => '1,3'];

    new CategoryAdminService()->saveImageOrder(3, null, true);

    $calls = category_admin_service_calls_since_last_reset();
    expect($calls)->toHaveCount(3);
    expect($calls[0]['args'][0])->toContain('SET image_order = NULL')
        ->and($calls[1]['fn'])->toBe('get_cat_info')
        ->and($calls[2]['args'][0])->toContain('uppercats LIKE \'1,3,%\'');
});

test('getCategoriesRefDate returns null for an id with no matching ref_date', function (): void {
    $GLOBALS['category_admin_service_test_query2array'] = [];

    $result = new CategoryAdminService()->getCategoriesRefDate([1, 2]);

    expect($result)->toBe([1 => null, 2 => null]);
});

test('createVirtualCategory maps a create_virtual_category() error to a failed CreateCategoryResult', function (): void {
    $GLOBALS['category_admin_service_test_create_result'] = ['error' => 'The name of an album must not be empty'];

    $result = new CategoryAdminService()->createVirtualCategory('');

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('The name of an album must not be empty')
        ->and($result->categoryId)->toBeNull();
});

test('createVirtualCategory maps a create_virtual_category() success to a successful CreateCategoryResult', function (): void {
    $GLOBALS['category_admin_service_test_create_result'] = ['info' => 'Album added', 'id' => 42];

    $result = new CategoryAdminService()->createVirtualCategory('New Album');

    expect($result->success)->toBeTrue()
        ->and($result->message)->toBe('Album added')
        ->and($result->categoryId)->toBe(42);
});

}
