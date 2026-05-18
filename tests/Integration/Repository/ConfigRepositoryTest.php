<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Config\ConfigRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see ConfigRepository}. Locks in the
 * F7-a contract: piwigo_config.value is a JSON column and the repository
 * is the encode/decode boundary — callers pass and receive native PHP
 * scalars/arrays/null without thinking about JSON.
 */
final class ConfigRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private ConfigRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new ConfigRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_upsertParamValue_round_trips_string(): void
    {
        $this->repo->upsertParamValue('test_str', 'hello world');

        self::assertSame('hello world', $this->repo->findValueByParam('test_str'));
    }

    public function test_upsertParamValue_round_trips_bool(): void
    {
        $this->repo->upsertParamValue('test_bool_true', true);
        $this->repo->upsertParamValue('test_bool_false', false);

        self::assertTrue($this->repo->findValueByParam('test_bool_true'));
        self::assertFalse($this->repo->findValueByParam('test_bool_false'));
    }

    public function test_upsertParamValue_round_trips_int(): void
    {
        $this->repo->upsertParamValue('test_int', 42);

        self::assertSame(42, $this->repo->findValueByParam('test_int'));
    }

    public function test_upsertParamValue_round_trips_array(): void
    {
        // MySQL 8 JSON object storage normalises key order alphabetically;
        // the values round-trip but assertEquals (loose) is the right
        // matcher rather than assertSame.
        $arr = ['field' => 'date_available', 'dir' => 'DESC'];
        $this->repo->upsertParamValue('test_array', $arr);

        self::assertEquals($arr, $this->repo->findValueByParam('test_array'));
    }

    public function test_upsertParamValue_round_trips_list_of_arrays(): void
    {
        // The F7-b orderBy shape — lists preserve insertion order in JSON.
        $list = [
            ['field' => 'date_available', 'dir' => 'DESC'],
            ['field' => 'id',             'dir' => 'ASC'],
        ];
        $this->repo->upsertParamValue('test_list', $list);

        $back = $this->repo->findValueByParam('test_list');
        self::assertIsArray($back);
        self::assertCount(2, $back);
        self::assertIsArray($back[0]);
        self::assertIsArray($back[1]);
        self::assertSame('date_available', $back[0]['field']);
        self::assertSame('DESC', $back[0]['dir']);
        self::assertSame('id', $back[1]['field']);
        self::assertSame('ASC', $back[1]['dir']);
    }

    public function test_upsertParamValue_round_trips_null(): void
    {
        $this->repo->upsertParamValue('test_null', null);

        self::assertNull($this->repo->findValueByParam('test_null'));
    }

    public function test_upsertParamValue_is_idempotent_on_existing_key(): void
    {
        $this->repo->upsertParamValue('overwrite_me', 'v1');
        $this->repo->upsertParamValue('overwrite_me', 'v2');

        self::assertSame('v2', $this->repo->findValueByParam('overwrite_me'));
    }

    public function test_findValueByParam_returns_null_for_missing(): void
    {
        self::assertNull($this->repo->findValueByParam('nonexistent_key'));
    }

    public function test_insertIgnoreParamValue_skips_on_duplicate(): void
    {
        $this->repo->insertIgnoreParamValue('lock_token', 'first');
        $this->repo->insertIgnoreParamValue('lock_token', 'second');

        self::assertSame('first', $this->repo->findValueByParam('lock_token'));
    }

    public function test_paramExists_and_deleteParams_round_trip(): void
    {
        $this->repo->upsertParamValue('to_delete', 'temp');
        self::assertTrue($this->repo->paramExists('to_delete'));

        $this->repo->deleteParams(['to_delete']);

        self::assertFalse($this->repo->paramExists('to_delete'));
    }

    /**
     * F7-a regression guard: confirm the column-level JSON validation by
     * inspecting the raw stored representation — every fixture row must
     * be JSON_VALID.
     */
    public function test_all_seeded_rows_are_valid_json_or_null(): void
    {
        $invalid = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_config WHERE value IS NOT NULL AND NOT JSON_VALID(value)'
        )->fetchOne();
        self::assertSame(0, $invalid, 'fixture must contain only JSON-valid values');
    }
}
