<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permission\ImageVisibilityChecker;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;

/**
 * Piwigo\Permission\ImageVisibilityChecker -- has its own dedicated
 * tests/Integration/ImageVisibilityCheckerTest.php; this ports its 4
 * scenarios down to the Unit suite via the real-DB-no-HTTP pattern.
 * CurrentUserTestFactory::get() gracefully degrades to a memoized
 * fallback instance when Kernel isn't booted (already used this way by
 * PermissionServiceTest.php), so no Kernel::boot() is needed here.
 *
 * Fixture: category 1 ("Sample Album") has images 1-3; category 2
 * ("Nested Sub Album", a subcategory of 1) has images 4-5; each image
 * belongs to exactly one category.
 */
function imageVisibilityCheckerTestChecker(): ImageVisibilityChecker
{
    return new ImageVisibilityChecker(
        new PermissionRepository(EntityManagerFactory::build(DbConnection::build())),
        CurrentUserTestFactory::get()
    );
}

function imageVisibilityCheckerTestSetForbiddenCategories(string $forbiddenCategories): void
{
    CurrentUserTestFactory::get()->set(User::fromUserArray([
        'id' => 2,
        'status' => 'normal',
        'forbidden_categories' => $forbiddenCategories,
        'level' => '0',
    ]));
}

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
});

test('isVisibleToUser() returns true when nothing is forbidden', function (): void {
    imageVisibilityCheckerTestSetForbiddenCategories('0');
    $checker = imageVisibilityCheckerTestChecker();

    expect($checker->isVisibleToUser(ImageId::from(1)))
        ->toBeTrue()
        ->and($checker->isVisibleToUser(ImageId::from(4)))
        ->toBeTrue();
});

test('isVisibleToUser() returns false for an image in a forbidden category', function (): void {
    imageVisibilityCheckerTestSetForbiddenCategories('2');
    $checker = imageVisibilityCheckerTestChecker();

    expect($checker->isVisibleToUser(ImageId::from(4)))
        ->toBeFalse()
        ->and($checker->isVisibleToUser(ImageId::from(5)))
        ->toBeFalse();
});

test('isVisibleToUser() returns true for an image not in a forbidden category', function (): void {
    imageVisibilityCheckerTestSetForbiddenCategories('2');

    expect(imageVisibilityCheckerTestChecker()->isVisibleToUser(ImageId::from(1)))
        ->toBeTrue();
});

test('isVisibleToUser() reflects a revocation on the same connection', function (): void {
    // A permission revocation (CurrentUser::forbiddenCategories changing
    // mid-request-lifecycle, simulating the next real request after an
    // admin action) must be reflected immediately, not served from a
    // frozen prior value.
    $checker = imageVisibilityCheckerTestChecker();

    imageVisibilityCheckerTestSetForbiddenCategories('0');
    expect($checker->isVisibleToUser(ImageId::from(4)))
        ->toBeTrue();

    imageVisibilityCheckerTestSetForbiddenCategories('2');
    expect($checker->isVisibleToUser(ImageId::from(4)))
        ->toBeFalse();
});
