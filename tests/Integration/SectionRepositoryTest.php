<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
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
