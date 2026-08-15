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
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Event\WsInvokeAllowed;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsInvokeAuthorizer;

/**
 * Piwigo\Ws\WsInvokeAuthorizer -- the WS layer's own method-invocation
 * security check, split out of the former WsHelper god-class (P25 Stage
 * 1 step 6). No dedicated Integration/Browser spec of its own --
 * WsServerTest's own class docblock covers isInvokeAllowed()'s
 * guest-denied branch through a real WS round-trip.
 */
function wsInvokeAuthorizerTestSubject(bool $isAdmin, bool $guestAccess = true): WsInvokeAuthorizer
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

    return new WsInvokeAuthorizer($accessControl);
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('isInvokeAllowed always permits reflection.* methods, even for a below-Guest user', function (): void {
    $authorizer = wsInvokeAuthorizerTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'reflection.getMethodList', []);

    $result = $authorizer->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed always permits pwg.session.* methods, even for a below-Guest user', function (): void {
    $authorizer = wsInvokeAuthorizerTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.session.login', []);

    $result = $authorizer->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed denies a real method for a guest user when guestAccess is disabled', function (): void {
    $authorizer = wsInvokeAuthorizerTestSubject(isAdmin: false, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $authorizer->isInvokeAllowed($event);

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
    $authorizer = wsInvokeAuthorizerTestSubject(isAdmin: true, guestAccess: false);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $authorizer->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});

test('isInvokeAllowed permits a real method for a guest user when guestAccess is enabled', function (): void {
    $authorizer = wsInvokeAuthorizerTestSubject(isAdmin: false, guestAccess: true);
    $event = new WsInvokeAllowed(true, 'pwg.categories.getList', []);

    $result = $authorizer->isInvokeAllowed($event);

    expect($result->value)
        ->toBeTrue();
});
