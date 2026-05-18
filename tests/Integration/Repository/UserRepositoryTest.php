<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Users\UserRepository;

/**
 * Real-DB integration tests for {@see UserRepository}. The fixture seeds
 * 4 users: id 1 'fixture_admin' (webmaster), id 2 'guest', id 3
 * 'regular_user', id 4 'power_user'.
 *
 * Also covers the user_cache JSON round-trip (forbidden_categories /
 * image_access_list) introduced in F5-a — values are written as JSON
 * blobs and decoded back as int[].
 */
final class UserRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private UserRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new UserRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_findUsernamesByIds_returns_id_to_name_map(): void
    {
        $map = $this->repo->findUsernamesByIds('id', 'username', 'piwigo_users', [1, 2, 3]);

        // PHP coerces numeric string keys to ints when storing, so the
        // documented array<string, string> signature gets int keys at
        // runtime. Normalise via array_combine before asserting.
        $keys = array_map(strval(...), array_keys($map));
        self::assertSame(
            ['1' => 'fixture_admin', '2' => 'guest', '3' => 'regular_user'],
            array_combine($keys, array_values($map))
        );
    }

    public function test_findUsernamesByIds_returns_empty_for_empty_input(): void
    {
        self::assertSame([], $this->repo->findUsernamesByIds('id', 'username', 'piwigo_users', []));
    }

    public function test_findEmailByUserId_returns_admin_mail(): void
    {
        $mail = $this->repo->findEmailByUserId('mail_address', 'id', 'piwigo_users', 1);
        self::assertSame('fixture_admin@example.test', $mail);
    }

    public function test_findEmailByUserId_returns_empty_when_null(): void
    {
        // user 3 (regular_user) has NULL mail_address in the fixture.
        $mail = $this->repo->findEmailByUserId('mail_address', 'id', 'piwigo_users', 3);
        self::assertSame('', $mail);
    }

    /**
     * F5-a regression guard: user_cache.forbidden_categories is a JSON
     * column. The repo's insert path writes a JSON string; readers expect
     * an int[] after json_decode.
     */
    public function test_user_cache_forbidden_categories_round_trips_as_json(): void
    {
        // Pre-clean any existing row so the insert is reliable.
        $this->conn->executeStatement('DELETE FROM piwigo_user_cache WHERE user_id = 3');

        $this->repo->insertUserCacheRow(
            userId:               3,
            needUpdate:           false,
            cacheUpdateTime:      time(),
            forbiddenCatsJson:    '[2,5,8]',
            nbTotalImages:        0,
            lastPhotoDate:        null,
            imageAccessType:      'NOT IN',
            imageAccessListJson:  '[]',
        );

        $row = $this->conn->executeQuery(
            'SELECT forbidden_categories, image_access_list FROM piwigo_user_cache WHERE user_id = 3'
        )->fetchAssociative();
        self::assertIsArray($row);

        // JSON column round-trip: raw value comes back as JSON text; decode
        // and assert int[] shape.
        self::assertIsString($row['forbidden_categories']);
        self::assertIsString($row['image_access_list']);
        self::assertSame([2, 5, 8], json_decode($row['forbidden_categories'], true));
        self::assertSame([], json_decode($row['image_access_list'], true));
    }

    public function test_userCacheCategoryExists_returns_false_for_missing(): void
    {
        // No user_cache_categories rows seeded for user 3 + cat 9999.
        self::assertFalse($this->repo->userCacheCategoryExists(9999, 3));
    }
}
