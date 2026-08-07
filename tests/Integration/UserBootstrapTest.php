<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Users\UserService;
use LogicException;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Tests\Support\PageStateTestFactory;
use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Bootstrap\UserBootstrap;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\CurrentLogger;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\User\TryLogUser;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentUserTestFactory;

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
 * both end in a bare `exit()`/`exit;` -- genuinely unsafe to invoke from
 * this shared PHPUnit/Pest process (same "don't exercise what would kill
 * the test" reasoning as NoPhotoYetRendererTest's own documented
 * exclusion) -- confirmed via this session's own live investigation (a
 * deliberately-wrong password against the uploadAsync branch below really
 * does terminate the invoking PHP process), left uncovered.
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

    /** @var array<array-key, mixed> */
    private array $serverSnapshot = [];

    /** @var array<array-key, mixed> */
    private array $getSnapshot = [];

    /** @var array<array-key, mixed> */
    private array $postSnapshot = [];

    /** @var array<array-key, mixed> */
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
        // container-shared instance a real request would get, matching
        // RedirectService's own real production callers (singleton/
        // service-locator elimination campaign, Phase 6).
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        return $userService;
    }

    private function bootstrap(?WsContext $wsContext = null, ?DeploymentPolicy $deploymentPolicy = null): UserBootstrap
    {
        // The api_key branch that reads CurrentLogger ends in a bare
        // exit() and is deliberately left uncovered here (see this class's
        // own docblock) -- never actually read, so a fresh, never-set()
        // instance is fine.
        return new UserBootstrap(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), new ApiKeyRequestFlag(), new CurrentLogger(), $wsContext ?? new WsContext(), $deploymentPolicy ?? new DeploymentPolicy());
    }

    public function test_initialize_auto_registers_a_new_local_account_for_an_unknown_apache_remote_user(): void
    {
        $remoteUser = 'apache_new_user_' . bin2hex(random_bytes(4));
        $_SERVER['REMOTE_USER'] = $remoteUser;
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        try {
            $this->bootstrap(deploymentPolicy: new DeploymentPolicy(apacheAuthentication: true))->initialize();

            self::assertSame($remoteUser, CurrentUserTestFactory::get()->get()->username);
            $row = $this->conn->fetchAssociative(
                'SELECT id, username FROM piwigo_users WHERE username = ?',
                [$remoteUser]
            );
            self::assertIsArray($row);
            self::assertSame($remoteUser, $row['username']);
        } finally {
            $this->conn->executeStatement('DELETE FROM piwigo_users WHERE username = ?', [$remoteUser]);
        }
    }

    public function test_initialize_reuses_the_existing_account_for_a_known_apache_remote_user(): void
    {
        $_SERVER['REMOTE_USER'] = 'regular_user';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        $this->bootstrap(deploymentPolicy: new DeploymentPolicy(apacheAuthentication: true))->initialize();

        self::assertSame('regular_user', CurrentUserTestFactory::get()->get()->username);
        // No 2nd row was created for an account that already exists.
        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM piwigo_users WHERE username = ?',
            ['regular_user']
        );
        self::assertSame(1, is_numeric($count) ? (int) $count : 0);
    }

    public function test_initialize_calls_authKeyLogin_when_the_auth_query_parameter_is_present(): void
    {
        $_GET['auth'] = 'not-a-real-auth-key';
        $_POST = [];
        $_REQUEST = [];

        // A garbage key: AuthService::authKeyLogin() itself already has
        // full dedicated coverage (AuthServiceTest.php) for every shape of
        // key it accepts/rejects -- this only proves initialize() reaches
        // the call site at all and stays on the guest fallback since the
        // key never resolves to a real user.
        $this->bootstrap()->initialize();

        self::assertSame(CurrentConfigTestFactory::get()->guestId(), CurrentUserTestFactory::get()->get()->id->value);
    }

    public function test_initialize_logs_in_via_ws_uploadAsync_and_marks_the_session_connected_with(): void
    {
        $plainPassword = 'upload-async-pass-' . bin2hex(random_bytes(4));
        $hash = (new PasswordService(new PasswordRepository(EntityManagerFactory::build($this->conn)), new DeploymentPolicy()))->hash($plainPassword);
        $username = 'upload_async_user_' . bin2hex(random_bytes(4));
        $this->conn->executeStatement(
            'INSERT INTO piwigo_users (username, password, mail_address) VALUES (?, ?, NULL)',
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
            new SessionService(EntityManagerFactory::build($this->conn)->getRepository(SessionEntity::class),CurrentConfigTestFactory::get()),
            EventDispatcherTestFactory::get(),
            PageStateTestFactory::get(),
            CurrentUserTestFactory::get(),
            CurrentConfigTestFactory::get(),
            CurrentPathsTestFactory::get(),
        )->pwgLogin(...));

        $_SERVER = [];
        $_GET = [];
        $_POST = ['username' => $username, 'password' => $plainPassword];
        $_REQUEST = ['method' => 'pwg.images.uploadAsync', 'username' => $username, 'password' => $plainPassword];

        try {
            $this->bootstrap(new WsContext(true))->initialize();

            self::assertSame('pwg.images.uploadAsync', $_SESSION['connected_with'] ?? null);
        } finally {
            $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = ?', [$newUserId]);
        }
    }
}
