<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\CustomLogoController;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\CustomLogoController -- 3 constructor deps, no
 * template rendering. No dedicated Integration/Browser spec of its own.
 *
 * Only the "no custom logo configured" 404 branch is covered -- a fresh
 * CurrentConfig's own standardPagesSelectedLogoPath() defaults to null,
 * short-circuiting before StorageRegistry::get('local') is ever called
 * (empty factories array, never queried on this path). The real-file
 * happy path needs a real Flysystem disk with a real file on it, a
 * materially bigger unit of setup for 2 more branches (304 vs full read).
 */
function customLogoTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('__invoke returns 404 when no custom logo is configured', function (): void {
    $controller = new CustomLogoController(customLogoTestAccessControl(), new StorageRegistry([]), new CurrentConfig());

    $response = $controller(new ServerRequest('GET', '/logo.php'));

    expect($response->getStatusCode())
        ->toBe(404)
        ->and((string) $response->getBody())
        ->toBe('Not found');
});
