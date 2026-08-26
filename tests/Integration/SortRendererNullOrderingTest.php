<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\ArrayParameterType;
use Override;
use Piwigo\Common\ValueObject\PhotoSortOrder;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SortRenderer;
use Piwigo\Tests\Support\DbTransactionTestOverride;

/**
 * §10 of the SQL-modernization plan: MySQL always treats NULL as the
 * smallest value in an ORDER BY, so a nullable column sorted ASC puts NULLs
 * first and DESC puts them last -- but PostgreSQL's default is the reverse
 * convention (NULLS LAST for ASC, NULLS FIRST for DESC). SortRendererTest
 * (Unit) pins the exact CASE WHEN clause SortRenderer now emits for every
 * nullable field; this proves the clause actually produces MySQL's row
 * order for real, against whichever provider this suite is configured for
 * -- the divergence this item exists to close is invisible to a test that
 * only inspects the generated SQL string.
 */
final class SortRendererNullOrderingTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    /**
     * @var list<int>
     */
    private array $insertedImageIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->insertedImageIds !== []) {
            $conn = DbConnection::build();
            foreach ($this->insertedImageIds as $imageId) {
                $conn->delete('images', [
                    'id' => $imageId,
                ]);
            }

            $this->insertedImageIds = [];
        }

        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testAscendingOrderPutsANullDateAvailableFirst(): void
    {
        $earlyId = $this->insertImage('sort-renderer-null-ordering-early.jpg', '2026-01-01 00:00:00');
        $nullId = $this->insertImage('sort-renderer-null-ordering-null.jpg', null);
        $lateId = $this->insertImage('sort-renderer-null-ordering-late.jpg', '2026-06-01 00:00:00');

        self::assertSame(
            [$nullId, $earlyId, $lateId],
            $this->orderedIdsFor([$earlyId, $nullId, $lateId], 'ORDER BY date_available ASC')
        );
    }

    public function testDescendingOrderPutsANullDateAvailableLast(): void
    {
        $earlyId = $this->insertImage('sort-renderer-null-ordering-early-desc.jpg', '2026-01-01 00:00:00');
        $nullId = $this->insertImage('sort-renderer-null-ordering-null-desc.jpg', null);
        $lateId = $this->insertImage('sort-renderer-null-ordering-late-desc.jpg', '2026-06-01 00:00:00');

        self::assertSame(
            [$lateId, $earlyId, $nullId],
            $this->orderedIdsFor([$earlyId, $nullId, $lateId], 'ORDER BY date_available DESC')
        );
    }

    /**
     * @param  list<int>  $imageIds
     * @return list<int>
     */
    private function orderedIdsFor(array $imageIds, string $configFragment): array
    {
        $conn = DbConnection::build();
        $order = PhotoSortOrder::fromConfigFragment($configFragment);
        // toSqlBody(), not toSql(): QueryBuilder::orderBy() prepends its
        // own "ORDER BY " keyword.
        $orderBySqlBody = new SortRenderer($conn)
            ->toSqlBody($order);

        $ids = $conn->createQueryBuilder()
            ->select('id')
            ->from('images')
            ->where('id IN (:ids)')
            ->setParameter('ids', $imageIds, ArrayParameterType::INTEGER)
            ->orderBy($orderBySqlBody)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }

    private function insertImage(string $file, ?string $dateAvailable): int
    {
        $conn = DbConnection::build();
        $conn->insert('images', [
            'file' => $file,
            'date_available' => $dateAvailable,
        ]);
        $id = (int) $conn->lastInsertId();
        $this->insertedImageIds[] = $id;

        return $id;
    }
}
