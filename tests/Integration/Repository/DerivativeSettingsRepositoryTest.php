<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Image\DerivativeSettingsRepository;
use Piwigo\Image\WatermarkParams;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see DerivativeSettingsRepository}.
 * Verifies the JSON column round-trip on `derivative_settings.watermark_json`
 * and `derivative_settings.custom_json` (singleton-row repo, id = 1).
 *
 * Uses Tables::derivativeSettings() which reads Config::dbPrefix() —
 * seed Config in setUp.
 */
final class DerivativeSettingsRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private DerivativeSettingsRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabase();
        $this->loadFixture(self::FIXTURE);
        Config::loadArray(['db_prefix' => 'piwigo_']);
        $this->conn = $this->newDbalConnection();
        $this->repo = new DerivativeSettingsRepository($this->conn);

        // Fixture may seed a row from install; truncate for deterministic
        // assertions of the load-defaults path.
        $this->conn->executeStatement('TRUNCATE TABLE piwigo_derivative_settings');
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
        $this->conn->close();
    }

    public function test_load_returns_defaults_when_row_absent(): void
    {
        $loaded = $this->repo->load();

        self::assertSame(95, $loaded['quality']);
        self::assertInstanceOf(WatermarkParams::class, $loaded['watermark']);
        self::assertSame([], $loaded['custom']);
    }

    public function test_save_then_load_round_trips_quality_and_custom(): void
    {
        $this->repo->save(85, new WatermarkParams(), ['small' => 1, 'medium' => 2, 'large' => 3]);

        $loaded = $this->repo->load();

        // MySQL 8 JSON object storage normalises key order — assertEquals
        // is the right matcher for round-tripping a map.
        self::assertSame(85, $loaded['quality']);
        self::assertEquals(['small' => 1, 'medium' => 2, 'large' => 3], $loaded['custom']);
    }

    public function test_save_is_upsert_on_singleton_row(): void
    {
        $this->repo->save(70, new WatermarkParams(), []);
        $this->repo->save(80, new WatermarkParams(), ['xs' => 99]);

        $loaded = $this->repo->load();
        self::assertSame(80, $loaded['quality']);
        self::assertSame(['xs' => 99], $loaded['custom']);

        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_derivative_settings'
        )->fetchOne();
        self::assertSame(1, $count, 'singleton: at most one row');
    }

    public function test_save_writes_valid_json_to_both_json_columns(): void
    {
        $this->repo->save(90, new WatermarkParams(), ['custom_a' => 1]);

        $row = $this->conn->executeQuery(
            'SELECT JSON_VALID(watermark_json) AS w, JSON_VALID(custom_json) AS c FROM piwigo_derivative_settings WHERE id = 1'
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(1, $row['w']);
        self::assertSame(1, $row['c']);
    }
}
