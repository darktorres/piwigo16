<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Integrity;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomaliesRepository;
use Piwigo\Config\Config;

final class IntegrityIgnoredAnomaliesRepositoryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Config::reset();
        Config::loadArray(['db_prefix' => 'piwigo_']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testListForVersionFiltersByPiwigoVersion(): void
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['md5-a', 'md5-b']);

        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeQuery')
            ->with(
                $this->stringContains('SELECT anomaly_id FROM piwigo_integrity_ignored_anomalies WHERE piwigo_version = ?'),
                ['17.0.0']
            )
            ->willReturn($result);

        $repo = new IntegrityIgnoredAnomaliesRepository($conn);
        self::assertSame(['md5-a', 'md5-b'], $repo->listForVersion('17.0.0'));
    }

    public function testReplaceForVersionWithEmptyListJustClearsTable(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->matchesRegularExpression('/^DELETE FROM piwigo_integrity_ignored_anomalies$/'));

        (new IntegrityIgnoredAnomaliesRepository($conn))->replaceForVersion('17.0.0', []);
    }

    public function testReplaceForVersionWritesOneRowPerAnomaly(): void
    {
        $statements = [];
        $conn       = $this->createStub(Connection::class);
        $conn->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$statements): int {
                $statements[] = ['sql' => $sql, 'params' => $params];
                return 1;
            });

        (new IntegrityIgnoredAnomaliesRepository($conn))->replaceForVersion('17.0.0', ['md5-a', 'md5-b']);

        self::assertCount(3, $statements);
        self::assertStringContainsString('DELETE FROM piwigo_integrity_ignored_anomalies', $statements[0]['sql']);
        self::assertStringContainsString('INSERT IGNORE INTO piwigo_integrity_ignored_anomalies', $statements[1]['sql']);
        self::assertSame(['md5-a', '17.0.0'], $statements[1]['params']);
        self::assertSame(['md5-b', '17.0.0'], $statements[2]['params']);
    }

    public function testClearAllIssuesDeleteWithNoWhere(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->once())
            ->method('executeStatement')
            ->with($this->matchesRegularExpression('/^DELETE FROM piwigo_integrity_ignored_anomalies$/'));

        (new IntegrityIgnoredAnomaliesRepository($conn))->clearAll();
    }
}
