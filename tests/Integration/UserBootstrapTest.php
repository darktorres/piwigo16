<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Bootstrap\UserBootstrap;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\ConnectedWith;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\User\TryLogUser;
use Piwigo\Http\ResponseReadyException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\UserBootstrap::initialize() -- most of this method is
 * already exercised indirectly by the Browser suite (every real page load
 * goes through RequestBootstrap::connect() -> this method), but 3 real
 * branches are never naturally reached by any existing Unit/Integration/
 * Browser test:
 *
 *  - the Apache-authentication REMOTE_USER branch (DeploymentPolicy's own
 *    apacheAuthentication flag is off in every real test/dev environment),
 *    including the "no existing account for this remote user" auto-
 *    registration sub-branch.
 *  - the authKeyLogin() call site itself (resolveApacheRemoteUser() the
 *    pure function is Unit-tested; nothing calls initialize() with
 *    `$_GET['auth']` set).
 *  - the WS `pwg.images.uploadAsync` real-session-login success path.
 *
 * The 2 adjacent api_key-failure / uploadAsync-login-failure branches
 * used to end in a bare `exit()`/`exit;` -- genuinely unsafe to invoke
 * from this shared PHPUnit/Pest process (same "don't exercise what would
 * kill the test" reasoning as NoPhotoYetRendererTest's own documented
 * exclusion) -- confirmed via a live investigation (a
 * deliberately-wrong password against the uploadAsync branch below
 * really did terminate the invoking PHP process). P25 Stage 2 replaced
 * both with `throw new ResponseReadyException(...)` (the same pattern
 * RequestBootstrap's own bootstrap-phase short-circuits already use),
 * closing that gap -- see testInitializeThrowsAResponseReadyExceptionForAnInvalidApiKey()/
 * testInitializeThrowsAResponseReadyExceptionWhenUploadAsyncLoginFails()
 * below.
 *
 * tryLogUser()'s own 'try_log_user' event handler is registered by
 * RequestBootstrap::connect() (a real, always-executes-once registration,
 * not covered by any Request DTO) -- this test never boots
 * RequestBootstrap, so it registers the identical handler by hand for the
 * one test that needs a real login to succeed.
 */
final class UserBootstrapTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private Connection $conn;

    /**
     * @var array<array-key, mixed>
     */
    private array $serverSnapshot = [];

    /**
     * @var array<array-key, mixed>
     */
    private array $getSnapshot = [];

    /**
     * @var array<array-key, mixed>
     */
    private array $postSnapshot = [];

    /**
     * @var array<array-key, mixed>
     */
    private array $requestSnapshot = [];

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
        Kernel::boot();
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));

        $this->conn = DbConnection::build();

        $this->serverSnapshot = $_SERVER;
        $this->getSnapshot = $_GET;
        $this->postSnapshot = $_POST;
        $this->requestSnapshot = $_REQUEST;
        unset($_SESSION['connected_with'], $_SESSION['pwg_uid']);

        $sessionCookieName = session_name();
        if (is_string($sessionCookieName)) {
            unset($_COOKIE[$sessionCookieName]);
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        $_SERVER = $this->serverSnapshot;
        $_GET = $this->getSnapshot;
        $_POST = $this->postSnapshot;
        $_REQUEST = $this->requestSnapshot;
        unset($_SESSION['connected_with'], $_SESSION['pwg_uid']);

        EventDispatcherTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    private function userService(): UserService
    {
        // Kernel::boot() already ran in setUp() -- resolve the same
        // container-shared instance a real request would get.
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    private function bootstrap(?WsContext $wsContext = null, ?DeploymentPolicy $deploymentPolicy = null): UserBootstrap
    {
        // The api_key branch's own CurrentLogger read only happens past
        // the invalid-api_key throw this class's own docblock describes
        // (see testInitializeThrowsAResponseReadyExceptionForAnInvalidApiKey()
        // below, which never reaches it) -- a fresh, never-set() instance
        // is fine.
        return new UserBootstrap(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new ApiKeyRequestFlag(), new CurrentLogger(), $wsContext ?? new WsContext(), $deploymentPolicy ?? new DeploymentPolicy(), new ConnectedWithSession());
    }

    public function testInitializeAutoRegistersANewLocalAccountForAnUnknownApacheRemoteUser(): void
    {
        $remoteUser = 'apache_new_user_' . bin2hex(random_bytes(4));
        $_SERVER['REMOTE_USER'] = $remoteUser;
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        try {
            $this->bootstrap(deploymentPolicy: new DeploymentPolicy(apacheAuthentication: true))
                ->initialize();

            self::assertEquals(Username::from($remoteUser), CurrentUserTestFactory::get()->get()->username);
            $row = $this->conn->fetchAssociative(
                'SELECT id, username FROM users WHERE username = ?',
                [$remoteUser]
            );
            self::assertIsArray($row);
            self::assertSame($remoteUser, $row['username']);
        } finally {
            $this->conn->executeStatement('DELETE FROM users WHERE username = ?', [$remoteUser]);
        }
    }

    public function testInitializeReusesTheExistingAccountForAKnownApacheRemoteUser(): void
    {
        $_SERVER['REMOTE_USER'] = 'regular_user';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        $this->bootstrap(deploymentPolicy: new DeploymentPolicy(apacheAuthentication: true))
            ->initialize();

        self::assertEquals(Username::from('regular_user'), CurrentUserTestFactory::get()->get()->username);
        // No 2nd row was created for an account that already exists.
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM users WHERE username = ?',
            ['regular_user']
        );
        self::assertSame(1, $count);
    }

    public function testInitializeCallsAuthKeyLoginWhenTheAuthQueryParameterIsPresent(): void
    {
        $_GET['auth'] = 'not-a-real-auth-key';
        $_POST = [];
        $_REQUEST = [];

        // A garbage key: AuthService::authKeyLogin() itself already has
        // full dedicated coverage (AuthServiceTest.php) for every shape of
        // key it accepts/rejects -- this only proves initialize() reaches
        // the call site at all and stays on the guest fallback since the
        // key never resolves to a real user.
        $this->bootstrap()
            ->initialize();

        self::assertSame(CurrentConfigTestFactory::get()->guestId, CurrentUserTestFactory::get()->get()->id->value);
    }

    public function testInitializeLogsInViaWsUploadAsyncAndMarksTheSessionConnectedWith(): void
    {
        $plainPassword = 'upload-async-pass-' . bin2hex(random_bytes(4));
        $hash = new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy())->hash($plainPassword);
        $username = 'upload_async_user_' . bin2hex(random_bytes(4));
        $this->conn->executeStatement(
            'INSERT INTO users (username, password, mail_address) VALUES (?, ?, NULL)',
            [$username, $hash]
        );
        $newUserId = (int) $this->conn->lastInsertId();

        // Real production registration (RequestBootstrap::connect()'s own
        // call, ported verbatim) -- tryLogUser()'s 'try_log_user' event has
        // no handler at all otherwise, so it would always resolve to
        // false regardless of the real credentials below.
        EventDispatcherTestFactory::get()->addTypedHandler(TryLogUser::class, new AuthService(
            new AuthRepository(EntityManagerFactory::build($this->conn)),
            new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)),
            HtmlServiceTestFactory::build(),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            new CookieService(),
            EntityManagerFactory::build($this->conn)->getRepository(UserFailedLoginEntity::class),
            new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
            EventDispatcherTestFactory::get(),
            PageStateTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            CurrentPathsTestFactory::get(),
            EntityManagerFactory::build($this->conn),
            new ConnectedWithSession(),
        )->pwgLogin(...));

        $_SERVER = [];
        $_GET = [];
        $_POST = [
            'username' => $username,
            'password' => $plainPassword,
        ];
        $_REQUEST = [
            'method' => 'pwg.images.uploadAsync',
            'username' => $username,
            'password' => $plainPassword,
        ];

        try {
            $this->bootstrap(new WsContext(true))
                ->initialize();

            self::assertSame(ConnectedWith::UploadAsync, new ConnectedWithSession()->get());
        } finally {
            $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$newUserId]);
        }
    }

    public function testInitializeThrowsAResponseReadyExceptionForAnInvalidApiKey(): void
    {
        $_SERVER['HTTP_X_PIWIGO_API'] = 'not-a-real-api-key';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [
            'method' => 'pwg.images.getInfo',
        ];

        $exception = null;

        try {
            $this->bootstrap(new WsContext(true))
                ->initialize();
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        self::assertInstanceOf(ResponseReadyException::class, $exception);
        self::assertSame(401, $exception->response()->getStatusCode());
        self::assertStringContainsString('Invalid api_key', (string) $exception->response()->getBody());
    }

    public function testInitializeThrowsAResponseReadyExceptionWhenUploadAsyncLoginFails(): void
    {
        $plainPassword = 'upload-async-pass-' . bin2hex(random_bytes(4));
        $hash = new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy())->hash($plainPassword);
        $username = 'upload_async_bad_login_' . bin2hex(random_bytes(4));
        $this->conn->executeStatement(
            'INSERT INTO users (username, password, mail_address) VALUES (?, ?, NULL)',
            [$username, $hash]
        );
        $newUserId = (int) $this->conn->lastInsertId();

        // Same real registration as the success test above -- without it,
        // tryLogUser() would fail regardless of the credentials below,
        // which would prove nothing about the real rejection path.
        EventDispatcherTestFactory::get()->addTypedHandler(TryLogUser::class, new AuthService(
            new AuthRepository(EntityManagerFactory::build($this->conn)),
            new ActivityService(EntityManagerFactory::build($this->conn)->getRepository(ActivityEntity::class)),
            HtmlServiceTestFactory::build(),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()),
            new CookieService(),
            EntityManagerFactory::build($this->conn)->getRepository(UserFailedLoginEntity::class),
            new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
            EventDispatcherTestFactory::get(),
            PageStateTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            CurrentPathsTestFactory::get(),
            EntityManagerFactory::build($this->conn),
            new ConnectedWithSession(),
        )->pwgLogin(...));

        $_SERVER = [];
        $_GET = [];
        $_POST = [
            'username' => $username,
            'password' => 'definitely-wrong-password',
        ];
        $_REQUEST = [
            'method' => 'pwg.images.uploadAsync',
            'username' => $username,
            'password' => 'definitely-wrong-password',
        ];

        $exception = null;

        try {
            try {
                $this->bootstrap(new WsContext(true))
                    ->initialize();
            } catch (ResponseReadyException $e) {
                $exception = $e;
            }

            self::assertInstanceOf(ResponseReadyException::class, $exception);
            self::assertSame(200, $exception->response()->getStatusCode());
            self::assertStringContainsString('Invalid username/password', (string) $exception->response()->getBody());
        } finally {
            $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$newUserId]);
        }
    }
}
