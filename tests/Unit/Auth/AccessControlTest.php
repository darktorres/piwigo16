<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * Zero coverage existed for this class before this test, despite it
 * gating every U_EDIT/U_DELETE/U_VALIDATE template flag in
 * PictureCommentRenderer -- the "permission-check logic" Stage 1c's own
 * plan text calls out for the Picture domain. Every method here is a pure
 * read of CurrentUser/CurrentConfig, no DB access (see the class's own
 * docblock), so it's tested directly rather than only indirectly through
 * a renderer.
 */
function seedAccessControlUser(UserStatus $status, int $id = 1): void
{
    CurrentUser::set(new User(
        id: \Piwigo\Common\ValueObject\UserId::from($id),
        username: '',
        email: '',
        language: '',
        theme: '',
        status: $status,
        enabledHigh: false,
    ));
}

beforeEach(function (): void {
    seedAccessControlUser(UserStatus::Normal);
});

afterEach(function (): void {
    CurrentUser::reset();
    CurrentConfig::reset();
});

test('getUserStatus falls back to the current user when no explicit status is given', function (): void {
    seedAccessControlUser(UserStatus::Admin);

    expect(AccessControl::getUserStatus())->toBe('admin')
        ->and(AccessControl::getUserStatus('webmaster'))->toBe('webmaster');
});

test('isAGuest/isClassicUser/isAdmin/isWebmaster read the current user status', function (): void {
    seedAccessControlUser(UserStatus::Guest);
    expect(AccessControl::isAGuest())->toBeTrue()
        ->and(AccessControl::isClassicUser())->toBeFalse()
        ->and(AccessControl::isAdmin())->toBeFalse()
        ->and(AccessControl::isWebmaster())->toBeFalse();

    seedAccessControlUser(UserStatus::Normal);
    expect(AccessControl::isAGuest())->toBeFalse()
        ->and(AccessControl::isClassicUser())->toBeTrue()
        ->and(AccessControl::isAdmin())->toBeFalse();

    seedAccessControlUser(UserStatus::Admin);
    expect(AccessControl::isClassicUser())->toBeTrue()
        ->and(AccessControl::isAdmin())->toBeTrue()
        ->and(AccessControl::isWebmaster())->toBeFalse();

    seedAccessControlUser(UserStatus::Webmaster);
    expect(AccessControl::isAdmin())->toBeTrue()
        ->and(AccessControl::isWebmaster())->toBeTrue();
});

test('isGeneric is true only for the generic status', function (): void {
    seedAccessControlUser(UserStatus::Generic);
    expect(AccessControl::isGeneric())->toBeTrue();

    seedAccessControlUser(UserStatus::Normal);
    expect(AccessControl::isGeneric())->toBeFalse();
});

test('a guest without guest_access configured is below Guest level, and generic is pinned at Guest level', function (): void {
    CurrentConfig::setGuestAccess(false);
    expect(AccessControl::isAuthorizeStatus(\Piwigo\Core\AccessLevel::Guest, 'guest'))->toBeFalse();

    CurrentConfig::setGuestAccess(true);
    expect(AccessControl::isAuthorizeStatus(\Piwigo\Core\AccessLevel::Guest, 'guest'))->toBeTrue();

    expect(AccessControl::isAuthorizeStatus(\Piwigo\Core\AccessLevel::Guest, 'generic'))->toBeTrue()
        ->and(AccessControl::isAuthorizeStatus(\Piwigo\Core\AccessLevel::Classic, 'generic'))->toBeFalse();
});

test('checkStatus throws when the current user is under the required access level', function (): void {
    seedAccessControlUser(UserStatus::Guest);

    AccessControl::checkStatus(\Piwigo\Core\AccessLevel::Classic);
})->throws(RuntimeException::class, 'Access denied');

test('checkStatus does nothing when the current user meets the required access level', function (): void {
    seedAccessControlUser(UserStatus::Admin);

    AccessControl::checkStatus(\Piwigo\Core\AccessLevel::Classic);

    expect(true)->toBeTrue();
});

test('canManageComment denies a guest regardless of action or authorship', function (): void {
    seedAccessControlUser(UserStatus::Guest, id: 5);

    expect(AccessControl::canManageComment('edit', 5))->toBeFalse()
        ->and(AccessControl::canManageComment('delete', 5))->toBeFalse();
});

test('canManageComment denies a guest even when they own the comment and editing is enabled', function (): void {
    // userCanEditComment() defaults to false, so the test above alone
    // can't prove the guest check runs first (both give false either way)
    // -- enabling it, and matching the guest's own id to the comment
    // author, is the only way a skipped guest-guard would flip the result.
    seedAccessControlUser(UserStatus::Guest, id: 5);
    CurrentConfig::setUserCanEditComment(true);

    expect(AccessControl::canManageComment('edit', 5))->toBeFalse();
});

test('canManageComment denies an action outside delete/edit/validate', function (): void {
    seedAccessControlUser(UserStatus::Admin);

    expect(AccessControl::canManageComment('publish', 1))->toBeFalse();
});

test('canManageComment grants an admin every real action regardless of authorship', function (): void {
    seedAccessControlUser(UserStatus::Admin, id: 1);

    expect(AccessControl::canManageComment('delete', 999))->toBeTrue()
        ->and(AccessControl::canManageComment('edit', 999))->toBeTrue()
        ->and(AccessControl::canManageComment('validate', 999))->toBeTrue();
});

test('canManageComment lets a normal user edit their own comment only when user_can_edit_comment is enabled', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);

    CurrentConfig::setUserCanEditComment(false);
    expect(AccessControl::canManageComment('edit', 7))->toBeFalse();

    CurrentConfig::setUserCanEditComment(true);
    expect(AccessControl::canManageComment('edit', 7))->toBeTrue()
        ->and(AccessControl::canManageComment('edit', 8))->toBeFalse();
});

test('canManageComment lets a normal user delete their own comment only when user_can_delete_comment is enabled', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);

    CurrentConfig::setUserCanDeleteComment(false);
    expect(AccessControl::canManageComment('delete', 7))->toBeFalse();

    CurrentConfig::setUserCanDeleteComment(true);
    expect(AccessControl::canManageComment('delete', 7))->toBeTrue()
        ->and(AccessControl::canManageComment('delete', 8))->toBeFalse();
});

test('canManageComment never lets a normal user validate a comment', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);
    CurrentConfig::setUserCanEditComment(true);
    CurrentConfig::setUserCanDeleteComment(true);

    expect(AccessControl::canManageComment('validate', 7))->toBeFalse();
});

test('canManageComment compares the string-typed author id numerically', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);
    CurrentConfig::setUserCanEditComment(true);

    expect(AccessControl::canManageComment('edit', '7'))->toBeTrue();
});

test('canManageComment compares a string-typed author id numerically for delete too', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);
    CurrentConfig::setUserCanDeleteComment(true);

    expect(AccessControl::canManageComment('delete', '7'))->toBeTrue();
});

test('canManageComment denies a normal user on a null (anonymous) author id without throwing', function (): void {
    seedAccessControlUser(UserStatus::Normal, id: 7);
    CurrentConfig::setUserCanEditComment(true);
    CurrentConfig::setUserCanDeleteComment(true);

    expect(AccessControl::canManageComment('edit', null))->toBeFalse()
        ->and(AccessControl::canManageComment('delete', null))->toBeFalse()
        ->and(AccessControl::canManageComment('validate', null))->toBeFalse();
});

test('canManageComment lets an admin manage a comment with a null (anonymous) author id', function (): void {
    seedAccessControlUser(UserStatus::Admin);

    expect(AccessControl::canManageComment('delete', null))->toBeTrue();
});

// AccessControl is a static utility -- the private no-op constructor
// (matching AccessLevel's own static-const convention, see the class's
// own docblock) is otherwise never invoked by any real code path.
// Reflection-invoking it directly is the same pattern this repo already
// uses for other setter-only/static-utility classes with no real
// instantiation site (see tests/Unit/Admin/ThemesInstalledPageRendererTest.php).
test('the private constructor exists purely to block real instantiation', function (): void {
    $reflection = new ReflectionClass(AccessControl::class);
    $constructor = $reflection->getConstructor();
    if ($constructor === null) {
        throw new RuntimeException('AccessControl has no constructor');
    }

    expect($constructor->isPrivate())->toBeTrue();

    $instance = $reflection->newInstanceWithoutConstructor();
    $constructor->invoke($instance);

    expect($instance)->toBeInstanceOf(AccessControl::class);
});

/**
 * setHtmlRenderer()/setRedirectService() are setter-only statics with no
 * public reset (same shape documented by FilesystemHelperTest.php for
 * Piwigo\Core\FilesystemHelper::$htmlRenderer) -- reflection sets/restores
 * them directly here so this doesn't leak a non-null renderer/redirect
 * service into every other test in this process (checkStatus()'s own
 * "Access denied" tests above rely on both being null).
 */
function accessControlTestSetRenderer(?HtmlRenderingInterface $renderer): void
{
    $prop = new ReflectionProperty(AccessControl::class, 'htmlRenderer');
    $prop->setValue(null, $renderer);
}

function accessControlTestSetRedirectService(?\Piwigo\Core\RedirectServiceInterface $redirectService): void
{
    $prop = new ReflectionProperty(AccessControl::class, 'redirectService');
    $prop->setValue(null, $redirectService);
}

test('checkStatus calls the installed HtmlRenderingInterface accessDenied() before throwing, when both are wired', function (): void {
    seedAccessControlUser(UserStatus::Guest);

    $renderer = new AccessControlTestFakeHtmlRendererDeniesAccess();
    accessControlTestSetRenderer($renderer);
    accessControlTestSetRedirectService(new AccessControlTestFakeRedirectServiceNeverCalled());

    try {
        $thrown = null;
        try {
            AccessControl::checkStatus(\Piwigo\Core\AccessLevel::Classic);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        // accessDenied() is `never`-typed and the fake throws
        // RuntimeException itself -- checkStatus()'s own trailing
        // `throw new \RuntimeException('Access denied')` is unreachable
        // once accessDenied() is called, so the marker message proves
        // accessDenied() (not the fallback) produced this exception.
        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown?->getMessage())->toBe('ACCESS_CONTROL_ACCESS_DENIED_MARKER')
            ->and($renderer->accessDeniedWasCalled)->toBeTrue();
    } finally {
        accessControlTestSetRenderer(null);
        accessControlTestSetRedirectService(null);
    }
});

test('checkStatus skips accessDenied() and throws the plain fallback when only htmlRenderer is wired', function (): void {
    // Both instanceof checks are required (a real AND, not an OR) -- with
    // only htmlRenderer set and redirectService still null, accessDenied()
    // must NOT be called (calling it would pass null where
    // RedirectServiceInterface is required and throw a TypeError instead
    // of the plain fallback below).
    seedAccessControlUser(UserStatus::Guest);

    $renderer = new AccessControlTestFakeHtmlRendererDeniesAccess();
    accessControlTestSetRenderer($renderer);

    try {
        $thrown = null;
        try {
            AccessControl::checkStatus(\Piwigo\Core\AccessLevel::Classic);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown?->getMessage())->toBe('Access denied')
            ->and($renderer->accessDeniedWasCalled)->toBeFalse();
    } finally {
        accessControlTestSetRenderer(null);
    }
});

test('checkStatus skips accessDenied() and throws the plain fallback when only redirectService is wired', function (): void {
    seedAccessControlUser(UserStatus::Guest);

    accessControlTestSetRedirectService(new AccessControlTestFakeRedirectServiceNeverCalled());

    try {
        expect(fn () => AccessControl::checkStatus(\Piwigo\Core\AccessLevel::Classic))
            ->toThrow(RuntimeException::class, 'Access denied');
    } finally {
        accessControlTestSetRedirectService(null);
    }
});
