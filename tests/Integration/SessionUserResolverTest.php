<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Session\SessionUserResolver;

/**
 * [SEC-33] resolveLoggedUserId() against real session table rows -- same
 * fixture/style as tests/Integration/SessionRepositoryTest.php. Sessions are
 * written directly through the real SessionRepository, keyed exactly the way
 * AuthService::login() would key them (SessionService::remoteAddrHash()
 * . cookie), so resolveLoggedUserId()'s own composite-key read is exercised
 * against genuine rows, not a fake.
 */
final class SessionUserResolverTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SessionRepository $repo;

    private SessionUserResolver $resolver;

    private ?string $originalRemoteAddr;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->repo = EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class);
        $this->resolver = new SessionUserResolver($this->repo);
        $this->originalRemoteAddr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : null;
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddr;
        }
        parent::tearDown();
    }

    public function test_resolves_the_logged_user_id_from_a_real_session_row(): void
    {
        $cookie = 'sur-test-' . bin2hex(random_bytes(8));
        $this->repo->write($cookie, 'pwg_uid|i:3;pwg_remember|b:0;');

        $userId = $this->resolver->resolveLoggedUserId($cookie, false);

        self::assertSame(3, $userId);

        $this->repo->destroy($cookie);
    }

    public function test_returns_null_for_a_cookie_with_no_matching_session_row(): void
    {
        $cookie = 'sur-test-never-issued-' . bin2hex(random_bytes(8));

        $userId = $this->resolver->resolveLoggedUserId($cookie, false);

        self::assertNull($userId);
    }

    public function test_returns_null_when_the_session_data_has_no_pwg_uid(): void
    {
        $cookie = 'sur-test-anon-' . bin2hex(random_bytes(8));
        $this->repo->write($cookie, 'some_other_key|s:5:"value";');

        $userId = $this->resolver->resolveLoggedUserId($cookie, false);

        self::assertNull($userId);

        $this->repo->destroy($cookie);
    }

    public function test_resolves_via_the_ip_bound_composite_key_when_use_ip_address_in_key_is_true(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $cookie = 'sur-test-ip-' . bin2hex(random_bytes(8));
        $compositeId = SessionService::remoteAddrHash(true) . $cookie;
        $this->repo->write($compositeId, 'pwg_uid|i:4;');

        $userId = $this->resolver->resolveLoggedUserId($cookie, true);

        self::assertSame(4, $userId);

        $this->repo->destroy($compositeId);
    }

    public function test_returns_null_for_the_bare_cookie_when_the_row_was_written_ip_bound(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $cookie = 'sur-test-ip-mismatch-' . bin2hex(random_bytes(8));
        $compositeId = SessionService::remoteAddrHash(true) . $cookie;
        $this->repo->write($compositeId, 'pwg_uid|i:4;');

        // Reading it back with useIpAddressInKey=false looks up the bare
        // cookie value, not the ip-prefixed composite id actually written --
        // must miss.
        $userId = $this->resolver->resolveLoggedUserId($cookie, false);

        self::assertNull($userId);

        $this->repo->destroy($compositeId);
    }
}
