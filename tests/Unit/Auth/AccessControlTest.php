<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

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

final class AccessControlTestFakeHtmlRendererDeniesAccess implements HtmlRenderingInterface
{
    public bool $accessDeniedWasCalled = false;

    #[\Override]
    public function getCatDisplayName(array $catInformations, ?string $url = ''): string
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function getCatDisplayNameCache(
        string $uppercats,
        ?string $url = '',
        bool $singleLink = false,
        ?string $linkClass = null,
        ?string $authKey = null,
    ): string {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function nameCompare(array $a, array $b): int
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function tagAlphaCompare(array $a, array $b): int
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function accessDenied(\Piwigo\Core\RedirectServiceInterface $redirectService): never
    {
        $this->accessDeniedWasCalled = true;
        throw new \RuntimeException('ACCESS_CONTROL_ACCESS_DENIED_MARKER');
    }

    #[\Override]
    public function badRequest(\Piwigo\Core\RedirectServiceInterface $redirectService, string $msg, ?string $alternateUrl = null): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function pageNotFound(\Piwigo\Core\RedirectServiceInterface $redirectService, ?string $msg, ?string $alternateUrl = null): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function fatalError(string $msg, ?string $title = null, bool $showTrace = true): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function getTagsContentTitle(array $tags): string
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function getCombinedCategoriesContentTitle(?array $category, array $combinedCategories): string
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function setStatusHeader(int $code, string $text = ''): void
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function renderElementName(array $info): string
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function renderElementDescription(array $info, string $param = ''): string
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function getThumbnailTitle(array $info, string $title, string $comment = ''): string
    {
        throw new \LogicException('not used by checkStatus()');
    }
}

final class AccessControlTestFakeRedirectServiceNeverCalled implements \Piwigo\Core\RedirectServiceInterface
{
    #[\Override]
    public function redirectHttp(string $url, int $status = 302): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never
    {
        throw new \LogicException('not used by checkStatus()');
    }

    #[\Override]
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never
    {
        throw new \LogicException('not used by checkStatus()');
    }
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
