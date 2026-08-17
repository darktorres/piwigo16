<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Bootstrap\UserBootstrap;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\UserService;

/**
 * Piwigo\Bootstrap\UserBootstrap::initialize() -- most of this method is
 * already exercised indirectly by the Browser suite (every real page load
 * goes through RequestBootstrap::connect() -> this method), but 2 real
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
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));

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

    private function bootstrap(?DeploymentPolicy $deploymentPolicy = null): UserBootstrap
    {
        return new UserBootstrap(new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), new RedirectService(LangTestFactory::get(), $this->userService(), EventDispatcherTestFactory::get(), PageStateTestFactory::get()), UrlServiceTestFactory::build(), $deploymentPolicy ?? new DeploymentPolicy(), new ConnectedWithSession());
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

    /**
     * A session's own pwg_uid can outlive the `users` row it names (the
     * user was deleted after the session was established -- a real
     * scenario in the Browser suite, where one test's session/background
     * beacon can still be in flight when another test deletes its own
     * throwaway fixture user). buildUser()/getUserData() throw a raw
     * Exception for a missing row -- confirmed live before this fix
     * (UserService::getUserData(): no such user_id N in the app log) --
     * this must degrade to guest instead of propagating.
     */
    public function testInitializeFallsBackToGuestWhenTheSessionsUserWasDeleted(): void
    {
        $username = 'ub_stale_session_' . bin2hex(random_bytes(4));
        $this->conn->executeStatement(
            'INSERT INTO users (username, password, mail_address) VALUES (?, NULL, NULL)',
            [$username]
        );
        $userId = (int) $this->conn->fetchOne('SELECT id FROM users WHERE username = ?', [$username]);
        $this->conn->executeStatement('DELETE FROM users WHERE id = ?', [$userId]);

        $sessionCookieName = session_name();
        self::assertIsString($sessionCookieName);
        $_COOKIE[$sessionCookieName] = 'stale-session-id';
        $_SESSION['pwg_uid'] = $userId;
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        $this->bootstrap()
            ->initialize();

        self::assertSame(CurrentConfigTestFactory::get()->guestId, CurrentUserTestFactory::get()->get()->id->value);
        self::assertArrayNotHasKey('pwg_uid', $_SESSION);
    }
}
