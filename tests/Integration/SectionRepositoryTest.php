<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Section\SectionRepository;

/**
 * Piwigo\Section\SectionRepository -- had no dedicated test file (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1); only
 * ever exercised indirectly through SectionPopulator/SectionInitializer's
 * own hand-built SQL. Same direct-repository-method pattern as
 * SearchRepositoryTest's own sibling class.
 */
final class SectionRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private SectionRepository $repo;

    private Connection $conn;

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

        $this->conn = DbConnection::build();
        $this->repo = new SectionRepository($this->conn);
    }

    public function test_query_column_returns_a_list_of_string_values(): void
    {
        $ids = $this->repo->queryColumn('SELECT id FROM ' . Tables::images() . ' ORDER BY id');

        self::assertSame(['1', '2', '3', '4', '5'], $ids);
    }

    public function test_query_column_returns_an_empty_list_for_no_matches(): void
    {
        $ids = $this->repo->queryColumn('SELECT id FROM ' . Tables::images() . ' WHERE id = -1');

        self::assertSame([], $ids);
    }

    public function test_execute_statement_runs_a_real_mutating_query(): void
    {
        $this->repo->executeStatement(
            'UPDATE ' . Tables::images() . " SET name = 'ct-section-repo-name' WHERE id = 1"
        );

        try {
            $name = $this->conn->fetchOne('SELECT name FROM ' . Tables::images() . ' WHERE id = 1');
            self::assertSame('ct-section-repo-name', $name);
        } finally {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::images() . " SET name = 'Photo 1' WHERE id = 1"
            );
        }
    }

    public function test_escape_token_escapes_a_value_without_surrounding_quotes(): void
    {
        $escaped = $this->repo->escapeToken("o'brien");

        self::assertSame("o\\'brien", $escaped);
        self::assertStringStartsNotWith("'", $escaped);
        self::assertStringEndsNotWith("'", $escaped);
    }

    public function test_escape_token_leaves_a_plain_value_unchanged(): void
    {
        self::assertSame('plain-value', $this->repo->escapeToken('plain-value'));
    }
}
