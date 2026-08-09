<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Auth;

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Core\AccessLevel;
use Throwable;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use RuntimeException;

/**
 * Every method here is a pure read of CurrentUser/CurrentConfig, with no
 * DB access (see the class's own docblock), so it's tested directly rather
 * than only indirectly through a renderer -- it gates every
 * U_EDIT/U_DELETE/U_VALIDATE template flag in PictureCommentRenderer.
 *
 * AccessControl is a constructor-injected instance (HtmlRenderingInterface/
 * RedirectServiceInterface/CurrentUser), so every test below constructs
 * its own fresh instance via accessControlTestMake() instead of
 * reflecting into static properties, and no beforeEach()/afterEach()
 * CurrentUser seed/reset is needed. CurrentConfig's own reset() still runs
 * after every test.
 */
function seedAccessControlUser(UserStatus $status, int $id = 1): CurrentUser
{
    $currentUser = new CurrentUser(new CurrentConfig());
    $currentUser->set(new User(
        id: UserId::from($id),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: '',
        status: $status,
        enabledHigh: false,
    ));

    return $currentUser;
}

/**
 * Both collaborators default to a fake that throws on every method except
 * accessDenied() (which the checkStatus() tests below individually
 * override to observe) -- every real caller always has both wired (a
 * container-resolved instance guarantees it), so no test here needs to
 * exercise a "not wired" scenario.
 */
function accessControlTestMake(
    UserStatus $status,
    int $id = 1,
    ?HtmlRenderingInterface $htmlRenderer = null,
    ?RedirectServiceInterface $redirectService = null,
    ?CurrentConfig $currentConfig = null,
): AccessControl {
    $currentUser = seedAccessControlUser($status, $id);
    $currentConfig ??= new CurrentConfig();

    return new AccessControl(
        $htmlRenderer ?? new AccessControlTestFakeHtmlRendererDeniesAccess(),
        $redirectService ?? new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('getUserStatus falls back to the current user when no explicit status is given', function (): void {
    $accessControl = accessControlTestMake(UserStatus::Admin);

    expect($accessControl->getUserStatus())->toBe('admin')
        ->and($accessControl->getUserStatus('webmaster'))->toBe('webmaster');
});

test('isAGuest/isClassicUser/isAdmin/isWebmaster read the current user status', function (): void {
    $guest = accessControlTestMake(UserStatus::Guest);
    expect($guest->isAGuest())->toBeTrue()
        ->and($guest->isClassicUser())->toBeFalse()
        ->and($guest->isAdmin())->toBeFalse()
        ->and($guest->isWebmaster())->toBeFalse();

    $normal = accessControlTestMake(UserStatus::Normal);
    expect($normal->isAGuest())->toBeFalse()
        ->and($normal->isClassicUser())->toBeTrue()
        ->and($normal->isAdmin())->toBeFalse();

    $admin = accessControlTestMake(UserStatus::Admin);
    expect($admin->isClassicUser())->toBeTrue()
        ->and($admin->isAdmin())->toBeTrue()
        ->and($admin->isWebmaster())->toBeFalse();

    $webmaster = accessControlTestMake(UserStatus::Webmaster);
    expect($webmaster->isAdmin())->toBeTrue()
        ->and($webmaster->isWebmaster())->toBeTrue();
});

test('isGeneric is true only for the generic status', function (): void {
    expect(accessControlTestMake(UserStatus::Generic)->isGeneric())->toBeTrue();
    expect(accessControlTestMake(UserStatus::Normal)->isGeneric())->toBeFalse();
});

test('a guest without guest_access configured is below Guest level, and generic is pinned at Guest level', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, currentConfig: $currentConfig);

    $currentConfig->setGuestAccess(false);
    expect($accessControl->isAuthorizeStatus(AccessLevel::Guest, 'guest'))->toBeFalse();

    $currentConfig->setGuestAccess(true);
    expect($accessControl->isAuthorizeStatus(AccessLevel::Guest, 'guest'))->toBeTrue();

    expect($accessControl->isAuthorizeStatus(AccessLevel::Guest, 'generic'))->toBeTrue()
        ->and($accessControl->isAuthorizeStatus(AccessLevel::Classic, 'generic'))->toBeFalse();
});

test('checkStatus does nothing when the current user meets the required access level', function (): void {
    expect(static fn () => accessControlTestMake(UserStatus::Admin)->checkStatus(AccessLevel::Classic))->not->toThrow(Throwable::class);
});

/**
 * A constructed AccessControl instance always has both collaborators
 * wired, so accessDenied() is unconditionally reached on denial.
 */
test('checkStatus calls the installed HtmlRenderingInterface accessDenied() before throwing', function (): void {
    $renderer = new AccessControlTestFakeHtmlRendererDeniesAccess();
    $accessControl = accessControlTestMake(UserStatus::Guest, htmlRenderer: $renderer);

    $thrown = null;
    try {
        $accessControl->checkStatus(AccessLevel::Classic);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    // accessDenied() is `never`-typed and the fake throws RuntimeException
    // itself -- the marker message proves accessDenied() produced this
    // exception, not some other path.
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toBe('ACCESS_CONTROL_ACCESS_DENIED_MARKER')
        ->and($renderer->accessDeniedWasCalled)->toBeTrue();
});

test('canManageComment denies a guest regardless of action or authorship', function (): void {
    $accessControl = accessControlTestMake(UserStatus::Guest, id: 5);

    expect($accessControl->canManageComment('edit', 5))->toBeFalse()
        ->and($accessControl->canManageComment('delete', 5))->toBeFalse();
});

test('canManageComment denies a guest even when they own the comment and editing is enabled', function (): void {
    // userCanEditComment() defaults to false, so the test above alone
    // can't prove the guest check runs first (both give false either way)
    // -- enabling it, and matching the guest's own id to the comment
    // author, is the only way a skipped guest-guard would flip the result.
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Guest, id: 5, currentConfig: $currentConfig);
    $currentConfig->setUserCanEditComment(true);

    expect($accessControl->canManageComment('edit', 5))->toBeFalse();
});

test('canManageComment denies an action outside delete/edit/validate', function (): void {
    expect(accessControlTestMake(UserStatus::Admin)->canManageComment('publish', 1))->toBeFalse();
});

test('canManageComment grants an admin every real action regardless of authorship', function (): void {
    $accessControl = accessControlTestMake(UserStatus::Admin, id: 1);

    expect($accessControl->canManageComment('delete', 999))->toBeTrue()
        ->and($accessControl->canManageComment('edit', 999))->toBeTrue()
        ->and($accessControl->canManageComment('validate', 999))->toBeTrue();
});

test('canManageComment lets a normal user edit their own comment only when user_can_edit_comment is enabled', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);

    $currentConfig->setUserCanEditComment(false);
    expect($accessControl->canManageComment('edit', 7))->toBeFalse();

    $currentConfig->setUserCanEditComment(true);
    expect($accessControl->canManageComment('edit', 7))->toBeTrue()
        ->and($accessControl->canManageComment('edit', 8))->toBeFalse();
});

test('canManageComment lets a normal user delete their own comment only when user_can_delete_comment is enabled', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);

    $currentConfig->setUserCanDeleteComment(false);
    expect($accessControl->canManageComment('delete', 7))->toBeFalse();

    $currentConfig->setUserCanDeleteComment(true);
    expect($accessControl->canManageComment('delete', 7))->toBeTrue()
        ->and($accessControl->canManageComment('delete', 8))->toBeFalse();
});

test('canManageComment never lets a normal user validate a comment', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);
    $currentConfig->setUserCanEditComment(true);
    $currentConfig->setUserCanDeleteComment(true);

    expect($accessControl->canManageComment('validate', 7))->toBeFalse();
});

test('canManageComment compares the string-typed author id numerically', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);
    $currentConfig->setUserCanEditComment(true);

    expect($accessControl->canManageComment('edit', '7'))->toBeTrue();
});

test('canManageComment compares a string-typed author id numerically for delete too', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);
    $currentConfig->setUserCanDeleteComment(true);

    expect($accessControl->canManageComment('delete', '7'))->toBeTrue();
});

test('canManageComment denies a normal user on a null (anonymous) author id without throwing', function (): void {
    $currentConfig = new CurrentConfig();
    $accessControl = accessControlTestMake(UserStatus::Normal, id: 7, currentConfig: $currentConfig);
    $currentConfig->setUserCanEditComment(true);
    $currentConfig->setUserCanDeleteComment(true);

    expect($accessControl->canManageComment('edit', null))->toBeFalse()
        ->and($accessControl->canManageComment('delete', null))->toBeFalse()
        ->and($accessControl->canManageComment('validate', null))->toBeFalse();
});

test('canManageComment lets an admin manage a comment with a null (anonymous) author id', function (): void {
    expect(accessControlTestMake(UserStatus::Admin)->canManageComment('delete', null))->toBeTrue();
});
