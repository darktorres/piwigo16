<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Piwigo\Auth\ApiKeyRepository;
use Piwigo\Auth\Projection\ApiKey;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * Piwigo\Auth\ApiKeyRepository -- has no dedicated Integration test file
 * of its own (spec ported down from tests/Integration/
 * ApiKeyServiceLifecycleTest.php + ApiKeyServiceGetAvailableTest.php,
 * which exercise it only indirectly through ApiKeyService's own real
 * create/revoke/rename flows). Real DB, no HTTP -- same
 * ImageRepositoryTest.php precedent as every other Unit-suite Repository
 * test. `user_id` FK's onto the real fixture admin row (id 1,
 * fixture_admin) -- read but never mutated, same as every other
 * fixture-referencing test in this codebase.
 */
const API_KEY_TEST_USER_ID = 1;

function apiKeyTestRepo(): ApiKeyRepository
{
    return new ApiKeyRepository(EntityManagerFactory::build(DbConnection::build()));
}

/**
 * @param array{auth_key?: string, apikey_secret?: string, apikey_name?: string, user_id?: int, created_on?: string, duration?: ?int, expired_on?: string, key_type?: string} $overrides
 * @return array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: ?int, expired_on: string, key_type: string}
 */
function apiKeyTestRow(array $overrides = []): array
{
    return array_merge([
        'auth_key' => 'ut-authkey-' . bin2hex(random_bytes(8)),
        'apikey_secret' => 'ut-secret',
        'apikey_name' => 'unit test key',
        'user_id' => API_KEY_TEST_USER_ID,
        'created_on' => '2026-08-01 12:00:00',
        'duration' => 30,
        'expired_on' => '2026-09-01 12:00:00',
        'key_type' => 'api_key',
    ], $overrides);
}

function apiKeyTestDelete(Connection $conn, string $authKey): void
{
    $conn->createQueryBuilder()
        ->delete('user_auth_keys')
        ->where('auth_key = :authKey')
        ->setParameter('authKey', $authKey)
        ->executeStatement();
}

test('insert() persists every column as given', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        apiKeyTestRepo()->insert($row);

        $stored = $conn->createQueryBuilder()
            ->select('auth_key', 'apikey_secret', 'apikey_name', 'user_id', 'created_on', 'duration', 'expired_on', 'key_type')
            ->from('user_auth_keys')
            ->where('auth_key = :authKey')
            ->setParameter('authKey', $row['auth_key'])
            ->fetchAssociative();

        expect($stored)
            ->toBe([
                'auth_key' => $row['auth_key'],
                'apikey_secret' => 'ut-secret',
                'apikey_name' => 'unit test key',
                'user_id' => API_KEY_TEST_USER_ID,
                'created_on' => '2026-08-01 12:00:00',
                'duration' => 30,
                'expired_on' => '2026-09-01 12:00:00',
                'key_type' => 'api_key',
            ]);
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('countByAuthKeyAndUser() counts only rows matching both the auth key and the user', function (): void {
    // getSingleScalarResult() on a COUNT() DQL query already returns a
    // real, native PHP int on this driver -- confirmed live via a raw,
    // uncast createQueryBuilder() COUNT() call -- so
    // countByAuthKeyAndUser()'s own is_numeric()/(int)/:0 ternary is a
    // confirmed-equivalent branch on this exact driver (a COUNT() result
    // is always numeric, so the false/default-0 side is unreachable
    // through real query execution), same root cause already documented
    // in ImageRepositoryTest.php. No dedicated test for the cast or the
    // 0 default; the strict toBe(int) assertions below would already
    // fail if this driver ever started returning a numeric string
    // instead.
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        expect($repo->countByAuthKeyAndUser($row['auth_key'], API_KEY_TEST_USER_ID))->toBe(1)
            ->and($repo->countByAuthKeyAndUser($row['auth_key'], 999999))->toBe(0)
            ->and($repo->countByAuthKeyAndUser('nonexistent-key', API_KEY_TEST_USER_ID))
            ->toBe(0);
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('revoke() sets revoked_on and leaves every other column untouched', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $repo->revoke($row['auth_key'], API_KEY_TEST_USER_ID, new DateTimeImmutable('2026-08-05 10:00:00'));

        $stored = $conn->createQueryBuilder()
            ->select('revoked_on', 'apikey_name', 'duration')
            ->from('user_auth_keys')
            ->where('auth_key = :authKey')
            ->setParameter('authKey', $row['auth_key'])
            ->fetchAssociative();

        expect($stored)
            ->toBe([
                'revoked_on' => '2026-08-05 10:00:00',
                'apikey_name' => 'unit test key',
                'duration' => 30,
            ]);
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('revoke() clears the EntityManager identity map, so a later read reflects the real new value instead of a stale cached entity', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        // Hydrates and caches the entity in this EntityManager's own
        // identity map (revokedOn still null at this point).
        $before = $repo->findByUser(API_KEY_TEST_USER_ID);
        $beforeMatch = array_values(array_filter($before, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));
        expect($beforeMatch[0]->revokedOn)->toBeNull();

        // revoke() updates the DB via a bulk DQL UPDATE, which bypasses
        // entity hydration entirely -- without its own em->clear(), the
        // identity map above would still hand back the same, now-stale
        // PHP object on the next read.
        $repo->revoke($row['auth_key'], API_KEY_TEST_USER_ID, new DateTimeImmutable('2026-08-05 10:00:00'));

        $after = $repo->findByUser(API_KEY_TEST_USER_ID);
        $afterMatch = array_values(array_filter($after, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));

        expect($afterMatch[0]->revokedOn)->toBe('2026-08-05 10:00:00');
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('updateName() renames only the row matching both the auth key and the user', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $repo->updateName($row['auth_key'], API_KEY_TEST_USER_ID, 'renamed key');

        $name = $conn->createQueryBuilder()
            ->select('apikey_name')
            ->from('user_auth_keys')
            ->where('auth_key = :authKey')
            ->setParameter('authKey', $row['auth_key'])
            ->fetchOne();

        expect($name)
            ->toBe('renamed key');
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('updateName() clears the EntityManager identity map, so a later read reflects the real new value instead of a stale cached entity', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $before = $repo->findByUser(API_KEY_TEST_USER_ID);
        $beforeMatch = array_values(array_filter($before, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));
        expect($beforeMatch[0]->apikeyName)->toBe('unit test key');

        $repo->updateName($row['auth_key'], API_KEY_TEST_USER_ID, 'renamed key');

        $after = $repo->findByUser(API_KEY_TEST_USER_ID);
        $afterMatch = array_values(array_filter($after, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));

        expect($afterMatch[0]->apikeyName)->toBe('renamed key');
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('updateName() accepts null to clear the name', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $repo->updateName($row['auth_key'], API_KEY_TEST_USER_ID, null);

        $name = $conn->createQueryBuilder()
            ->select('apikey_name')
            ->from('user_auth_keys')
            ->where('auth_key = :authKey')
            ->setParameter('authKey', $row['auth_key'])
            ->fetchOne();

        expect($name)
            ->toBeNull();
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('updateLastNotifiedOn() clears the EntityManager identity map, so a later read reflects the real new value instead of a stale cached entity', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $before = $repo->findByUser(API_KEY_TEST_USER_ID);
        $beforeMatch = array_values(array_filter($before, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));
        expect($beforeMatch[0]->lastNotifiedOn)->toBeNull();

        $repo->updateLastNotifiedOn($row['auth_key'], API_KEY_TEST_USER_ID, '2026-08-06 08:00:00');

        $after = $repo->findByUser(API_KEY_TEST_USER_ID);
        $afterMatch = array_values(array_filter($after, static fn (ApiKey $k): bool => $k->authKey === $row['auth_key']));

        expect($afterMatch[0]->lastNotifiedOn)->toBe('2026-08-06 08:00:00');
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('updateLastNotifiedOn() records when the near-expiration email was sent', function (): void {
    $conn = DbConnection::build();
    $row = apiKeyTestRow();

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($row);

        $repo->updateLastNotifiedOn($row['auth_key'], API_KEY_TEST_USER_ID, '2026-08-06 08:00:00');

        $lastNotifiedOn = $conn->createQueryBuilder()
            ->select('last_notified_on')
            ->from('user_auth_keys')
            ->where('auth_key = :authKey')
            ->setParameter('authKey', $row['auth_key'])
            ->fetchOne();

        expect($lastNotifiedOn)
            ->toBe('2026-08-06 08:00:00');
    } finally {
        apiKeyTestDelete($conn, $row['auth_key']);
    }
});

test('findByUser() returns only api_key-typed rows for that user, not auth_key-typed (login) rows', function (): void {
    $conn = DbConnection::build();
    $apiRow = apiKeyTestRow([
        'key_type' => 'api_key',
    ]);
    $loginRow = apiKeyTestRow([
        'key_type' => 'auth_key',
    ]);

    try {
        $repo = apiKeyTestRepo();
        $repo->insert($apiRow);
        $repo->insert($loginRow);

        $found = $repo->findByUser(API_KEY_TEST_USER_ID);
        $foundAuthKeys = array_map(static fn (ApiKey $k): string => $k->authKey, $found);

        expect($foundAuthKeys)
            ->toContain($apiRow['auth_key'])
            ->and($foundAuthKeys)
            ->not->toContain($loginRow['auth_key'])
            // Not just duck-typed by a same-named property -- proves
            // findByUser() really maps through ApiKey::fromEntity(),
            // not the raw UserAuthKeyEntity (which also happens to
            // expose ->authKey).
            ->and($found[0])->toBeInstanceOf(ApiKey::class);
    } finally {
        apiKeyTestDelete($conn, $apiRow['auth_key']);
        apiKeyTestDelete($conn, $loginRow['auth_key']);
    }
});
