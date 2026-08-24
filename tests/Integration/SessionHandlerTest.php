<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionHandler;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;

/**
 * Piwigo\Session\SessionHandler -- the SessionHandlerInterface adapter
 * registered via session_set_save_handler(), had zero dedicated coverage.
 * SessionServiceTest (Unit suite) deliberately only covers the
 * DB-independent methods (an unreachable db_host, by design); the real
 * DB-backed sessionRead()/sessionWrite()/sessionDestroy()/sessionGc()
 * this adapter also delegates to had no coverage at either layer, so this
 * closes both gaps at once with a real DB connection.
 *
 * Real, live-confirmed finding along the way (not "fixed" here -- out of
 * scope): CurrentConfig::sessionUseIpAddress()
 * defaults to true, and SessionService::remoteAddrHash(true) crashes with
 * an uncaught ValueError ("arguments array must contain 2 items, 1
 * given") whenever $_SERVER['REMOTE_ADDR'] is empty -- explode('.', '')
 * yields a 1-element array, but vsprintf('%02X%02X', ...) needs 2. Every
 * real HTTP request always has a REMOTE_ADDR (set by the web server), so
 * this never fires in production traffic; it only surfaces in a bare
 * CLI/test process with no request context, which is exactly this test
 * process without the explicit REMOTE_ADDR set below.
 */
final class SessionHandlerTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SessionHandler $pwgSession;

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
        $repo = TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class);

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $service = new SessionService($repo, $currentConfig);

        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }

        $this->pwgSession = new SessionHandler($service, $currentLogger);
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

    public function testOpenAndCloseAlwaysReturnTrue(): void
    {
        self::assertTrue($this->pwgSession->open('/tmp', 'pwg'));
        self::assertTrue($this->pwgSession->close());
    }

    public function testReadReturnsAnEmptyStringForAnUnknownSessionId(): void
    {
        self::assertSame('', $this->pwgSession->read('ct-unknown-' . bin2hex(random_bytes(4))));
    }

    public function testWriteThenReadRoundTripsTheSameData(): void
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

    public function testWriteWhenTheUnderlyingWriteFailsCatchesTheErrorAndReturnsFalse(): void
    {
        // Composite session id (remote-addr hash prefix + this id)
        // exceeds the sessions table's real VARCHAR(50) primary key -- a
        // genuine DB-level constraint violation, not a mock.
        self::assertFalse($this->pwgSession->write(str_repeat('x', 60), 'data'));
    }

    public function testWriteWhenTheUnderlyingWriteFailsAndCurrentLoggerIsNotInitialisedStillReturnsFalseInsteadOfThrowing(): void
    {
        // Real bug found live via a full composer test:integration run:
        // write()'s own fallback logging used to call
        // CurrentLogger::get() unconditionally inside its catch block --
        // if CurrentLogger isn't initialised at the exact moment PHP's
        // own deferred session auto-close fires this method (see this
        // class's own docblock), that fallback ->get() itself threw,
        // escaping uncaught exactly the way this whole try/catch exists
        // to prevent.
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        self::assertInstanceOf(CurrentLogger::class, $currentLogger);
        $currentLogger->reset();

        self::assertFalse($this->pwgSession->write(str_repeat('x', 60), 'data'));
    }

    public function testWriteThenDestroyRemovesTheSessionRow(): void
    {
        $sessionId = 'ct-session-destroy-' . bin2hex(random_bytes(4));
        $this->pwgSession->write($sessionId, 'some-data');
        self::assertNotSame('', $this->pwgSession->read($sessionId));

        self::assertTrue($this->pwgSession->destroy($sessionId));

        self::assertSame('', $this->pwgSession->read($sessionId));
    }

    public function testDestroyIsASafeNoOpForAnUnknownSessionId(): void
    {
        self::assertTrue($this->pwgSession->destroy('ct-does-not-exist-' . bin2hex(random_bytes(4))));
    }

    public function testGcReturnsARealIntegerAndLeavesAFreshSessionUntouched(): void
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
