<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Auth\PasswordResetRequestEntity;
use Piwigo\Auth\PasswordResetRequestRepository;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Command\MaintenancePurgeFailedLoginsCommand;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MaintenancePurgeFailedLoginsCommandTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

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
    }

    public function testPurgesOldFailedLoginsResetRequestsAndStaleIntegrityIgnoresButKeepsRecentOnes(): void
    {
        $conn = DbConnection::build();
        $em = EntityManagerFactory::build($conn);
        $failedLoginRepo = TypedRepository::narrow($em->getRepository(UserFailedLoginEntity::class), UserFailedLoginRepository::class);
        $passwordResetRequestRepo = TypedRepository::narrow($em->getRepository(PasswordResetRequestEntity::class), PasswordResetRequestRepository::class);
        $ignoredAnomalyRepo = TypedRepository::narrow($em->getRepository(IntegrityIgnoredAnomalyEntity::class), IntegrityIgnoredAnomalyRepository::class);

        $failedLoginRepo->recordFailure(1, '203.0.113.10', '2000-01-01 00:00:00');
        $failedLoginRepo->recordFailure(1, '203.0.113.10', date('Y-m-d H:i:s'));

        $passwordResetRequestRepo->recordRequest(1, '203.0.113.10', '2000-01-01 00:00:00');
        $passwordResetRequestRepo->recordRequest(1, '203.0.113.10', date('Y-m-d H:i:s'));

        $ignoredAnomalyRepo->syncForVersion('16.0.0', ['old-anomaly'], '2000-01-01 00:00:00');
        $ignoredAnomalyRepo->syncForVersion('99.0.0', ['recent-anomaly'], date('Y-m-d H:i:s'));

        try {
            $command = new MaintenancePurgeFailedLoginsCommand($failedLoginRepo, $passwordResetRequestRepo, $ignoredAnomalyRepo);
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([]);

            self::assertSame(Command::SUCCESS, $exitCode);

            $remainingFailedLogins = $conn->fetchOne('SELECT COUNT(*) FROM user_failed_logins WHERE user_id = 1');
            self::assertSame(1, $remainingFailedLogins);

            $remainingResetRequests = $conn->fetchOne('SELECT COUNT(*) FROM password_reset_requests WHERE user_id = 1');
            self::assertSame(1, $remainingResetRequests);

            $remainingOld = $conn->fetchOne('SELECT COUNT(*) FROM integrity_ignored_anomalies' . " WHERE anomaly_id = 'old-anomaly'");
            self::assertSame(0, $remainingOld);

            $remainingRecent = $conn->fetchOne('SELECT COUNT(*) FROM integrity_ignored_anomalies' . " WHERE anomaly_id = 'recent-anomaly'");
            self::assertSame(1, $remainingRecent);
        } finally {
            $conn->executeStatement('DELETE FROM user_failed_logins WHERE user_id = 1');
            $conn->executeStatement('DELETE FROM password_reset_requests WHERE user_id = 1');
            $conn->executeStatement("DELETE FROM integrity_ignored_anomalies WHERE anomaly_id IN ('old-anomaly', 'recent-anomaly')");
        }
    }
}
