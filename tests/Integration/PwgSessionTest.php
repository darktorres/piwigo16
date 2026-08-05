<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use Piwigo\Config\CurrentConfig;
use LogicException;
use Piwigo\Core\CurrentLogger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\PwgSession;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;

/**
 * Piwigo\Session\PwgSession -- the SessionHandlerInterface adapter
 * registered via session_set_save_handler(), had zero dedicated coverage
 * (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * SessionServiceTest (Unit suite) deliberately only covers the
 * DB-independent methods (an unreachable db_host, by design); the real
 * DB-backed sessionRead()/sessionWrite()/sessionDestroy()/sessionGc()
 * this adapter also delegates to had no coverage at either layer, so this
 * closes both gaps at once with a real DB connection.
 *
 * Real, live-confirmed finding along the way (not "fixed" here -- out of
 * scope for a test-writing pass): CurrentConfig::sessionUseIpAddress()
 * defaults to true, and SessionService::remoteAddrHash(true) crashes with
 * an uncaught ValueError ("arguments array must contain 2 items, 1
 * given") whenever $_SERVER['REMOTE_ADDR'] is empty -- explode('.', '')
 * yields a 1-element array, but vsprintf('%02X%02X', ...) needs 2. Every
 * real HTTP request always has a REMOTE_ADDR (set by the web server), so
 * this never fires in production traffic; it only surfaces in a bare
 * CLI/test process with no request context, which is exactly this test
 * process without the explicit REMOTE_ADDR set below.
 */
final class PwgSessionTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PwgSession $pwgSession;

    private ?string $originalRemoteAddr = null;

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

        $this->originalRemoteAddr = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $conn = DbConnection::build();
        $repo = EntityManagerFactory::build($conn)->getRepository(SessionEntity::class);
        self::assertInstanceOf(SessionRepository::class, $repo);

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $service = new SessionService($repo, $currentConfig);

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        $this->pwgSession = new PwgSession($service, $currentLogger);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->originalRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->originalRemoteAddr;
        }
        parent::tearDown();
    }

    public function test_open_and_close_always_return_true(): void
    {
        self::assertTrue($this->pwgSession->open('/tmp', 'pwg'));
        self::assertTrue($this->pwgSession->close());
    }

    public function test_read_returns_an_empty_string_for_an_unknown_session_id(): void
    {
        self::assertSame('', $this->pwgSession->read('ct-unknown-' . bin2hex(random_bytes(4))));
    }

    public function test_write_then_read_round_trips_the_same_data(): void
    {
        $sessionId = 'ct-session-' . bin2hex(random_bytes(4));
        $data = 'pwg_uid|i:1;pwg_status|s:6:"normal";';

        try {
            self::assertTrue($this->pwgSession->write($sessionId, $data));
            self::assertSame($data, $this->pwgSession->read($sessionId));
        } finally {
            $this->pwgSession->destroy($sessionId);
        }
    }

    public function test_write_then_destroy_removes_the_session_row(): void
    {
        $sessionId = 'ct-session-destroy-' . bin2hex(random_bytes(4));
        $this->pwgSession->write($sessionId, 'some-data');
        self::assertNotSame('', $this->pwgSession->read($sessionId));

        self::assertTrue($this->pwgSession->destroy($sessionId));

        self::assertSame('', $this->pwgSession->read($sessionId));
    }

    public function test_destroy_is_a_safe_no_op_for_an_unknown_session_id(): void
    {
        self::assertTrue($this->pwgSession->destroy('ct-does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function test_gc_returns_a_real_integer_and_leaves_a_fresh_session_untouched(): void
    {
        $sessionId = 'ct-session-gc-' . bin2hex(random_bytes(4));

        try {
            $this->pwgSession->write($sessionId, 'fresh-data');

            $this->pwgSession->gc(3600);

            // A session just written is nowhere near sessionLength()
            // seconds old -- gc() must not have swept it up.
            self::assertSame('fresh-data', $this->pwgSession->read($sessionId));
        } finally {
            $this->pwgSession->destroy($sessionId);
        }
    }
}
