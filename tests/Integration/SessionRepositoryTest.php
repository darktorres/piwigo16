<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;

final class SessionRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SessionRepository $repo;

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

        $this->repo = TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), SessionRepository::class);
    }

    public function testWriteThenReadRoundTripsRealData(): void
    {
        $id = 'test-session-' . bin2hex(random_bytes(8));

        $this->repo->write($id, 'serialized-payload');

        self::assertSame('serialized-payload', $this->repo->read($id));

        $this->repo->destroy($id);
    }

    public function testWriteReplacesAnExistingRowForTheSameId(): void
    {
        $id = 'test-session-' . bin2hex(random_bytes(8));

        $this->repo->write($id, 'first');
        $this->repo->write($id, 'second');

        self::assertSame('second', $this->repo->read($id));

        $this->repo->destroy($id);
    }

    public function testReadReturnsEmptyStringForAMissingId(): void
    {
        self::assertSame('', $this->repo->read('does-not-exist-' . bin2hex(random_bytes(8))));
    }

    public function testDestroyRemovesTheRow(): void
    {
        $id = 'test-session-' . bin2hex(random_bytes(8));
        $this->repo->write($id, 'payload');

        $this->repo->destroy($id);

        self::assertSame('', $this->repo->read($id));
    }

    public function testGcDeletesOnlySessionsOlderThanTheCutoffAndReturnsTheCount(): void
    {
        $oldId = 'gc-old-' . bin2hex(random_bytes(8));
        $freshId = 'gc-fresh-' . bin2hex(random_bytes(8));

        $conn = DbConnection::build();

        // Backdate one row's expiration well past any real session_length,
        // insert the other with a fresh (now) expiration.
        //
        // Real bug found live -- REPLACE INTO is
        // MySQL-only syntax ("syntax error at or near 'REPLACE'").
        // Postgres's portable equivalent is INSERT ... ON CONFLICT (id)
        // DO UPDATE SET ... (id is sessions' own primary key), matching
        // REPLACE INTO's own upsert semantics exactly.
        $onConflict = $this->dbDriver === 'pgsql'
            ? 'ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expiration = EXCLUDED.expiration'
            : 'ON DUPLICATE KEY UPDATE data = VALUES(data), expiration = VALUES(expiration)';
        $conn->executeStatement(
            "INSERT INTO sessions (id, data, expiration) VALUES (?, ?, ?) {$onConflict}",
            [$oldId, 'stale', new DateTimeImmutable('-1 year')],
            [ParameterType::STRING, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
        $this->repo->write($freshId, 'fresh');

        $deleted = $this->repo->gc(3600);

        self::assertGreaterThanOrEqual(1, $deleted);
        self::assertSame('', $this->repo->read($oldId));
        self::assertSame('fresh', $this->repo->read($freshId));

        $this->repo->destroy($freshId);
    }

    public function testDeleteByUserIdRemovesMatchingSerializedSessions(): void
    {
        $id = 'test-session-' . bin2hex(random_bytes(8));
        // PHP's native session-serialize format (the "php" session.serialize_handler,
        // this app's actual $_SESSION wire format) is "name|value;", NOT
        // serialize()'s array format ("a:1:{s:7:\"pwg_uid\";i:987654;}") --
        // the LIKE pattern this repository method matches against expects
        // the former.
        $this->repo->write($id, 'pwg_uid|i:987654;');

        $this->repo->deleteByUserId(987654);

        self::assertSame('', $this->repo->read($id));
    }
}
