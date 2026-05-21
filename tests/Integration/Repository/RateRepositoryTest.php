<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Rate\RateRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see RateRepository}. Locks in the F8-c
 * contract: fk_rate_user_id ON DELETE CASCADE removes rates when their
 * owning user is deleted. Anonymous-rate disambiguation lives in the
 * `anonymous_id` varchar — multiple rates per (user_id, image) coexist as
 * long as their anonymous_id differs.
 */
final class RateRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private RateRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new RateRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insert_round_trips_via_findRateByUserAndElement(): void
    {
        $this->repo->insert(3, '127.0.0.1', 1, 4.0);

        $rate = $this->repo->findRateByUserAndElement(1, 3, '127.0.0.1');
        self::assertSame(4.0, $rate);
    }

    public function test_findRateByUserAndElement_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findRateByUserAndElement(1, 3, '127.0.0.1'));
    }

    public function test_composite_pk_allows_multiple_anonymous_rates_per_user_image(): void
    {
        // Guest user (id=2) with two different session fingerprints rates
        // the same image — both rows should coexist under the composite PK
        // (element_id, user_id, anonymous_id).
        $this->repo->insert(2, '10.0.0.1', 1, 3.0);
        $this->repo->insert(2, '10.0.0.2', 1, 5.0);

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_rate WHERE user_id = 2 AND element_id = 1'
        )->fetchOne();
        self::assertSame(2, $count, 'anonymous_id distinguishes guest-session rates');
    }

    public function test_findCountAndAvgByElementId_aggregates(): void
    {
        $this->repo->insert(1, '127.0.0.1', 1, 2.0);
        $this->repo->insert(3, '127.0.0.2', 1, 4.0);

        $result = $this->repo->findCountAndAvgByElementId(1);
        self::assertSame(2, $result->count);
        self::assertSame(3.0, $result->average);
    }

    /**
     * F8-c regression guard: fk_rate_user_id ON DELETE CASCADE — deleting
     * the user removes their rate rows.
     */
    public function test_user_delete_cascades_to_rate(): void
    {
        $this->repo->insert(3, '127.0.0.1', 1, 5.0);
        self::assertSame(1, $this->repo->countByElementId(1), 'precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        self::assertSame(0, $this->repo->countByElementId(1));
    }
}
