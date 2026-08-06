<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use RuntimeException;
use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\MessengerFactory;

/**
 * Proves the real Symfony Messenger mechanism end-to-end: dispatch onto
 * the Doctrine transport (a genuine DB round trip through
 * messenger_messages, auto-created via auto_setup) -> receive back ->
 * handle -> real domain-service side effect. Uses GenerateDerivativeJob
 * specifically because DerivativeCacheService's filesystem operations are
 * the only one of the 5 handlers with no DB-write/mail-send side effects
 * of their own to worry about isolating.
 *
 * The other 4 jobs' handlers are unit-level verified directly (calling
 * __invoke() without going through the transport) -- this test's job is
 * to prove the transport/bus wiring itself works, not to re-prove every
 * handler's delegation, already covered elsewhere.
 */
final class MessengerRoundTripTest extends IntegrationTestCase
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

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // See tests/Unit/Image/DerivativeCacheServiceTest.php's own
        // extensive comment on why a real filesystem root alone isn't
        // enough of a safeguard: scope every filesystem side effect to a
        // marker-suffixed subdirectory, verified before any destructive
        // operation.
        $currentConfig->setDataLocation($this->marker() . '/');
        mkdir(CurrentPathsTestFactory::get()->root . $currentConfig->derivativeDir(), 0o777, true);

        $this->conn = DbConnection::build();
        $this->conn->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    }

    #[Override]
    protected function tearDown(): void
    {
        $dir = CurrentPathsTestFactory::get()->root . $this->marker();
        if (is_dir($dir)) {
            $this->rrmdir($dir);
        }
        $this->conn->executeStatement('DROP TABLE IF EXISTS messenger_messages');
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        parent::tearDown();
    }

    private function marker(): string
    {
        /** @var string|null $marker */
        static $marker = null;

        return $marker ??= 'phpwg-messenger-test-marker-' . bin2hex(random_bytes(8));
    }

    private function rrmdir(string $dir): void
    {
        if (! str_contains($dir, $this->marker())) {
            throw new RuntimeException("Refusing to recursively delete '{$dir}': missing this test run's marker.");
        }
        $nodes = scandir($dir);
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if ($node === '.' || $node === '..') {
                continue;
            }
            $path = $dir . '/' . $node;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_a_dispatched_job_is_persisted_received_and_handled_via_the_real_doctrine_transport(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $derivDir = CurrentPathsTestFactory::get()->root . $currentConfig->derivativeDir() . '2026/07';
        mkdir($derivDir, 0o777, true);
        file_put_contents($derivDir . '/photo-th.jpg', 'x');
        file_put_contents($derivDir . '/photo-sq.jpg', 'x');

        $transport = MessengerFactory::transport($this->conn, CurrentPathsTestFactory::get());

        // dispatch: real INSERT into messenger_messages via the Doctrine
        // transport's send()
        $sendingBus = MessengerFactory::sendingBus($transport, CurrentPathsTestFactory::get());
        $sendingBus->dispatch(new GenerateDerivativeJob('2026/07/photo.jpg', type: 'thumb'));

        self::assertSame(1, $transport->getMessageCount());

        // receive: real SELECT ... FOR UPDATE SKIP LOCKED via the same
        // transport's get()
        $envelopes = iterator_to_array($transport->get());
        self::assertCount(1, $envelopes);
        $envelope = $envelopes[0];
        self::assertInstanceOf(GenerateDerivativeJob::class, $envelope->getMessage());

        // handle: routes through GenerateDerivativeHandler, a real call
        // into DerivativeCacheService::deleteElementDerivatives()
        $receivingBus = MessengerFactory::receivingBus(CurrentPathsTestFactory::get());
        $receivingBus->dispatch($envelope);
        $transport->ack($envelope);

        self::assertFileDoesNotExist($derivDir . '/photo-th.jpg');
        self::assertFileExists($derivDir . '/photo-sq.jpg');
        self::assertSame(0, $transport->getMessageCount());
    }
}
