<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\UserResolutionMiddleware;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\User\TryLogUser;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `Bootstrap\UserResolutionMiddleware` is the real, direct successor of
 * the last block of `Bootstrap\RequestBootstrap::connect()` (workstream
 * C3 Phase 1) -- the `AuthListener` handler registration + `UserBootstrap::
 * initialize()` call. `UserBootstrap::initialize()`'s own branches
 * (Apache-authentication, authKeyLogin(), the WS uploadAsync paths) are
 * already exhaustively covered by `UserBootstrapTest.php`, which
 * registers an equivalent `AuthListener` handler by hand since it never
 * boots a real middleware -- this file's own job is narrower and does
 * NOT duplicate that: prove this middleware itself performs the real
 * `TryLogUser` handler registration (the one piece `UserBootstrapTest.php`
 * fakes by hand) and that a normal call resolves a real (guest) user via
 * `UserBootstrap::initialize()`.
 */
final class UserResolutionMiddlewareTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        $this->conn = DbConnection::build();

        unset($_SERVER['REMOTE_USER'], $_GET['auth'], $_POST['username'], $_POST['password']);
        $_SERVER = array_diff_key($_SERVER, [
            'REMOTE_USER' => true,
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        EventDispatcherTestFactory::get()->reset();
        unset($_SERVER['REMOTE_USER'], $_GET['auth'], $_POST['username'], $_POST['password']);

        parent::tearDown();
    }

    public function testProcessResolvesAGuestUserByDefaultAndCallsTheNextHandler(): void
    {
        $middleware = Kernel::container()->get(UserResolutionMiddleware::class);
        self::assertInstanceOf(UserResolutionMiddleware::class, $middleware);

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());

        self::assertSame(200, $response->getStatusCode());
        self::assertEquals(Username::from('guest'), CurrentUserTestFactory::get()->get()->username);
    }

    /**
     * `connect()`'s own comment (ported verbatim into this middleware's
     * docblock) explains why the `TryLogUser` handler is registered here,
     * immediately before `UserBootstrap::initialize()`, rather than
     * alongside the other default event-handler registrations still in
     * `RequestBootstrap::finalize()` -- proves that registration is real,
     * not just present in the source, by dispatching a `TryLogUser` event
     * against real fixture-adjacent credentials right after `process()`
     * returns and confirming a real login succeeds.
     */
    public function testProcessRegistersARealTryLogUserHandler(): void
    {
        $plainPassword = 'user-resolution-mw-pass-' . bin2hex(random_bytes(4));
        $username = 'user_resolution_mw_user_' . bin2hex(random_bytes(4));
        $hash = new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy())->hash($plainPassword);
        $this->conn->executeStatement(
            'INSERT INTO users (username, password, mail_address) VALUES (?, ?, NULL)',
            [$username, $hash]
        );

        try {
            $middleware = Kernel::container()->get(UserResolutionMiddleware::class);
            self::assertInstanceOf(UserResolutionMiddleware::class, $middleware);
            $middleware->process(new ServerRequest('GET', '/'), $this->passthroughHandler());

            $event = new TryLogUser(success: false, username: $username, password: $plainPassword, rememberMe: false);
            $result = EventDispatcherTestFactory::get()->dispatch($event);

            self::assertTrue($result->success);
        } finally {
            $this->conn->executeStatement('DELETE FROM users WHERE username = ?', [$username]);
        }
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class() implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
