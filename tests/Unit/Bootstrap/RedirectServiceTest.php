<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserInfoEntity;
use Piwigo\Users\UserService;

// Workstream C3: redirectHttp() throws ResponseReadyException instead of
// calling header()/exit() directly, which is what makes it unit-testable
// for the first time -- there was never a RedirectServiceTest.php before
// this conversion (exit()-terminated methods can't be asserted on).
//
// redirectHttp() is typed `: never`, so PHPStan proves any code
// following a call to it never runs, in or out of a try block -- the
// exception is captured into a variable already declared before the try
// (never reassigned inside it under normal control flow) and asserted on
// afterwards, so every assertion sits in code PHPStan doesn't consider
// provably dead.

// Singleton/service-locator elimination campaign, Phase 6: RedirectService
// now takes UserService via constructor injection -- neither test below
// ever touches it (redirectHttp() never reaches $this->userService), so a
// throwaway, never-queried instance is enough. Doctrine's DBAL connection
// is lazy (build() never opens a real connection until a query runs), so
// this is safe to construct with no reachable test DB, same recipe used
// throughout this campaign (e.g. CoreUpdateServiceTest.php's own
// core_update_service_test_user_service()).
function redirect_service_test_user_service(): UserService
{
    $conn = DbConnection::build();

    return new UserService(
        EntityManagerFactory::build($conn)->getRepository(UserInfoEntity::class),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new MailService(),
        new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
        new HtmlService(),
        $conn,
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class)),
        new EventDispatcher(),
        new DeploymentPolicy(),
        new CurrentUser(),
    );
}

test('redirectHttp throws ResponseReadyException with a 302 redirect to the given URL', function (): void {
    $service = new RedirectService(redirect_service_test_user_service());
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    $response = $exception->response();
    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe('http://example.test/target.php');
});

test('redirectHttp html_entity_decode()s the URL before redirecting', function (): void {
    $service = new RedirectService(redirect_service_test_user_service());
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php?a=1&amp;b=2');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception)->toBeInstanceOf(ResponseReadyException::class);
    expect($exception->response()->getHeaderLine('Location'))->toBe('http://example.test/target.php?a=1&b=2');
});
