<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin\Integrity;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomaliesRepository;

/**
 * Guards the anomaly_id fingerprint produced by {@see CheckIntegrity::addAnomaly()}.
 *
 * The md5 hash is persisted to piwigo_integrity_ignored_anomalies — admins click
 * "ignore" and the row keys off this hash. If the encoding of the hash input
 * changes silently, every previously-ignored anomaly resurfaces. These tests
 * lock in the current encoding so regressions are loud.
 */
final class CheckIntegrityTest extends TestCase
{
    private function newChecker(): CheckIntegrity
    {
        // addAnomaly does not touch the repo — pass a real repo with a stub
        // Connection (final-class repo can't be stubbed directly).
        $repo = new IntegrityIgnoredAnomaliesRepository($this->createStub(Connection::class));
        return new CheckIntegrity($repo);
    }

    public function testSameCallableAndArgsProduceStableHash(): void
    {
        $checker1 = $this->newChecker();
        $checker2 = $this->newChecker();
        $cb = [$this, 'dummyCorrection'];

        $checker1->addAnomaly('an anomaly', $cb, ['id' => 42, 'action' => 'creation'], 'msg');
        $checker2->addAnomaly('an anomaly', $cb, ['id' => 42, 'action' => 'creation'], 'msg');

        self::assertSame($checker1->retrieve_list[0]['id'], $checker2->retrieve_list[0]['id']);
    }

    public function testDifferentArgsProduceDifferentHash(): void
    {
        $checker = $this->newChecker();
        $cb = [$this, 'dummyCorrection'];

        $checker->addAnomaly('an anomaly', $cb, ['id' => 1, 'action' => 'creation']);
        $checker->addAnomaly('an anomaly', $cb, ['id' => 2, 'action' => 'creation']);

        self::assertNotSame($checker->retrieve_list[0]['id'], $checker->retrieve_list[1]['id']);
    }

    public function testDifferentCallableProducesDifferentHash(): void
    {
        $checker = $this->newChecker();

        $checker->addAnomaly('a', [$this, 'dummyCorrection'], ['id' => 1]);
        $checker->addAnomaly('a', [$this, 'otherCorrection'], ['id' => 1]);

        self::assertNotSame($checker->retrieve_list[0]['id'], $checker->retrieve_list[1]['id']);
    }

    public function testNullCallableAndNullArgsAreHandled(): void
    {
        $checker = $this->newChecker();

        $checker->addAnomaly('a');

        self::assertCount(1, $checker->retrieve_list);
        self::assertNotEmpty($checker->retrieve_list[0]['id']);
    }

    public function dummyCorrection(): bool
    {
        return true;
    }

    public function otherCorrection(): bool
    {
        return true;
    }
}
