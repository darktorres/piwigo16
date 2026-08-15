<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\SqlDialect;
use Piwigo\Image\ImageFilterCriteria;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Event\WsInvokeAllowed;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * Piwigo\Ws\WsHelper -- shared web-service helpers, called from 2-4 of
 * the Ws\Pwg* classes each. No dedicated Integration/Browser spec of
 * its own -- `Piwigo\Tests\Contract\WsHelperTest.php` covers
 * `stdImageSqlFilterCriteria()`'s "invalid date field" branch (a
 * `WsErrorResponse` return, not an `exit()` -- see
 * `Piwigo\Ws\WsHelper::stdImageSqlFilterCriteria()`'s own docblock)
 * through a real WS round-trip, which this file deliberately does not
 * attempt.
 *
 * `stdGetUrls()` is not covered here -- it needs a real `SrcImage`/
 * `DerivativeImage::getAll()` pair, itself needing real `ImageStdParams`
 * derivative config and (for the `isOriginal()` branch) a real file on
 * disk, disproportionate for this class's own shared-helper role.
 */
function wsHelperTestSubject(bool $isAdmin, bool $guestAccess = true): WsHelper
{
    $currentConfig = new CurrentConfig();
    $currentConfig->guestAccess = $guestAccess;
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from($isAdmin ? 1 : 2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: $isAdmin ? UserStatus::Admin : UserStatus::Guest,
        enabledHigh: false,
    ));
    $accessControl = new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    return new WsHelper($accessControl, $currentUser);
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

// --------------------------------------------------------------- isInvokeAllowed

test('isInvokeAllowed always permits reflection.* methods, even for a below-Guest user', function (): void {
    $helper = wsHelperTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'reflection.getMethodList', []);

    $result = $helper->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed always permits pwg.session.* methods, even for a below-Guest user', function (): void {
    $helper = wsHelperTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.session.login', []);

    $result = $helper->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed denies a real method for a guest user when guestAccess is disabled', function (): void {
    $helper = wsHelperTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $helper->isInvokeAllowed($event);

    expect($result->value)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result->value instanceof WsErrorResponse) {
        expect($result->value->code())
            ->toBe(401)
            ->and($result->value->message())
            ->toBe('Access denied');
    }
});

test('isInvokeAllowed permits a real method for an admin user regardless of guestAccess', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $helper->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed permits a real method for a guest user when guestAccess is enabled', function (): void {
    $helper = wsHelperTestSubject(isAdmin: false, guestAccess: true);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $helper->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

// --------------------------------------------------------------- stdImageSqlFilterCriteria

test('stdImageSqlFilterCriteria builds the criteria from valid params, with no date fields set', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);
    $params = [
        'f_min_rate' => 1.5,
        'f_max_rate' => 5.0,
        'f_min_hit' => 0,
        'f_max_hit' => 100,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ];

    $criteria = $helper->stdImageSqlFilterCriteria($params);

    expect($criteria)
        ->toBeInstanceOf(ImageFilterCriteria::class);
    if (! $criteria instanceof ImageFilterCriteria) {
        return;
    }

    expect($criteria->minRate)
        ->toBe(1.5)
        ->and($criteria->maxRate)
        ->toBe(5.0)
        ->and($criteria->minHit)
        ->toBe(0)
        ->and($criteria->maxHit)
        ->toBe(100)
        ->and($criteria->minDateAvailable)
        ->toBeNull();
});

test('stdImageSqlFilterCriteria accepts a valid MySQL datetime for a date field', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);
    $params = [
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => '2026-01-01 00:00:00',
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ];

    $criteria = $helper->stdImageSqlFilterCriteria($params);

    expect($criteria)
        ->toBeInstanceOf(ImageFilterCriteria::class);
    if (! $criteria instanceof ImageFilterCriteria) {
        return;
    }

    expect($criteria->minDateAvailable)
        ->toBe('2026-01-01 00:00:00');
});

// --------------------------------------------------------------- stdImageSqlOrder

test('stdImageSqlOrder returns an empty string for a null/empty/zero order', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    expect($helper->stdImageSqlOrder([
        'order' => null,
    ]))->toBe('')
        ->and($helper->stdImageSqlOrder([
            'order' => '',
        ]))->toBe('')
        ->and($helper->stdImageSqlOrder([
            'order' => '0',
        ]))->toBe('');
});

test('stdImageSqlOrder resolves a single known token with its direction', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    $result = $helper->stdImageSqlOrder([
        'order' => 'file desc',
    ], 'i.');

    expect($result)
        ->toBe('i.file desc');
});

test('stdImageSqlOrder resolves multiple comma-separated tokens, table-prefixing each', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    $result = $helper->stdImageSqlOrder([
        'order' => 'hit asc, id desc',
    ], 'i.');

    expect($result)
        ->toBe('i.hit asc, i.id desc');
});

test('stdImageSqlOrder skips the table prefix for the random field', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    $result = $helper->stdImageSqlOrder([
        'order' => 'random',
    ], 'i.');

    // PhotoSortField::Random->column() delegates to
    // SqlDialect::randomFunction(), which returns a seeded RAND(timestamp)
    // under Env::testModeIsActive() rather than a bare RAND() -- reuse
    // that same real call instead of hardcoding the unseeded string.
    expect($result)
        ->toBe(SqlDialect::randomFunction() . ' ');
});

test('stdImageSqlOrder silently ignores an unrecognized token', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    $result = $helper->stdImageSqlOrder([
        'order' => 'not_a_real_field',
    ], 'i.');

    expect($result)
        ->toBe('');
});

// --------------------------------------------------------------- stdGetXmlAttributes

test('stdGetImageXmlAttributes returns the fixed image attribute list', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    expect($helper->stdGetImageXmlAttributes())
        ->toBe([
            'id', 'element_url', 'page_url', 'file', 'width', 'height', 'hit', 'date_available', 'date_creation',
        ]);
});

test('stdGetCategoryXmlAttributes returns the fixed category attribute list', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    expect($helper->stdGetCategoryXmlAttributes())
        ->toBe([
            'id', 'url', 'nb_images', 'total_nb_images', 'nb_categories', 'date_last', 'max_date_last', 'status',
        ]);
});

test('stdGetTagXmlAttributes returns the fixed tag attribute list', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);

    expect($helper->stdGetTagXmlAttributes())
        ->toBe([
            'id', 'name', 'url_name', 'counter', 'url', 'page_url',
        ]);
});

// --------------------------------------------------------------- categoriesFlatlistToTree

test('categoriesFlatlistToTree attaches a child under its real parent, root-level categories go straight into the tree', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);
    $categories = [
        [
            'id' => 1,
            'name' => 'Root',
        ],
        [
            'id' => 2,
            'name' => 'Child',
            'id_uppercat' => 1,
        ],
    ];

    $tree = $helper->categoriesFlatlistToTree($categories);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['name'])->toBe('Root')
        ->and($tree[0]['sub_categories'])->toBeInstanceOf(NamedArray::class);
    $subCategories = $tree[0]['sub_categories'];
    if ($subCategories instanceof NamedArray) {
        expect($subCategories->content)->toHaveCount(1);
        $child = $subCategories->content[0];
        if (is_array($child)) {
            expect($child['name'])->toBe('Child');
        }
    }
});

test('categoriesFlatlistToTree skips a row with a non-scalar id', function (): void {
    $helper = wsHelperTestSubject(isAdmin: true);
    $categories = [
        [
            'id' => 1,
            'name' => 'Root',
        ],
        [
            'id' => null,
            'name' => 'Malformed',
        ],
    ];

    $tree = $helper->categoriesFlatlistToTree($categories);

    expect($tree)
        ->toHaveCount(1)
        ->and($tree[0]['name'])->toBe('Root');
});
