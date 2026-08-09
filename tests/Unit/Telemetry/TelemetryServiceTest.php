<?php

declare(strict_types=1);

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;
use Piwigo\Telemetry\TelemetryService;
use Piwigo\Tests\Support\DqlPlatformQueryTestFactory;

/**
 * Piwigo\Telemetry\TelemetryService -- has its own dedicated
 * tests/Integration/TelemetryServiceTest.php; this is the same spec
 * ported down to the Unit suite via the real-DB-no-HTTP
 * ImageRepositoryTest.php pattern.
 *
 * 4 confirmed-equivalent mutations, not individually tested:
 * resolveInstallId()'s own `$entry->value !== ''` raw-empty-string check
 * is unreachable through any real write path -- confirmed live:
 * `piwigo_config.value` is a real MySQL JSON column, which rejects an
 * empty-string insert outright ("Invalid JSON text: The document is
 * empty"), and every real write goes through upsert()'s own
 * json_encode() anyway; `json_decode($entry->value, true)`'s
 * associative-array-mode flag makes no observable difference decoding a
 * plain JSON string literal (never a JSON object/array, so there's
 * nothing for assoc-mode to change); databaseInfo()'s `is_string($version)
 * ? ... : ''` fallback is unreachable in practice (`SELECT VERSION()`
 * always returns a real string on this driver); count()'s
 * `is_numeric($count) ? (int) $count : 0` fallback is unreachable too (a
 * COUNT() DQL result is always numeric) -- same root cause already
 * documented in ImageRepositoryTest.php/ApiKeyRepositoryTest.php.
 */
function telemetryTestService(): TelemetryService
{
    $conn = DbConnection::build();
    $em = EntityManagerFactory::build($conn);
    $configRepo = $em->getRepository(ConfigEntry::class);
    expect($configRepo)->toBeInstanceOf(ConfigRepository::class);

    return new TelemetryService($em, $configRepo);
}

test('resolveInstallId() generates and persists a random id on first use', function (): void {
    $conn = DbConnection::build();

    try {
        $id = telemetryTestService()->resolveInstallId();

        expect($id)->toMatch('/^[0-9a-f]{32}$/');

        $stored = $conn->createQueryBuilder()
            ->select('value')
            ->from(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->fetchOne();

        expect(is_string($stored) ? json_decode($stored, true) : null)->toBe($id);
    } finally {
        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->executeStatement();
    }
});

test('resolveInstallId() returns the same id on a later call instead of regenerating it', function (): void {
    $conn = DbConnection::build();

    try {
        $service = telemetryTestService();
        $first = $service->resolveInstallId();
        $second = telemetryTestService()->resolveInstallId();

        expect($second)->toBe($first);
    } finally {
        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->executeStatement();
    }
});

test('resolveInstallId() regenerates when the stored value decodes to something other than a non-empty string', function (): void {
    $conn = DbConnection::build();

    try {
        $conn->createQueryBuilder()
            ->insert(Tables::config())
            ->values(['param' => ':param', 'value' => ':value'])
            ->setParameter('param', 'telemetry_install_id')
            // Valid JSON, but a number, not a string -- is_string() must
            // reject it, not just any non-null/non-empty-string decode.
            ->setParameter('value', '12345')
            ->executeStatement();

        $id = telemetryTestService()->resolveInstallId();

        expect($id)->toMatch('/^[0-9a-f]{32}$/');
    } finally {
        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->executeStatement();
    }
});

test('detectDriverLabel() distinguishes MariaDB from plain MySQL/MariaDB\'s own subclass relationship, not just "is unknown"', function (): void {
    // detectDriverLabel() is private, and this environment's real
    // connection can never actually report MariaDBPlatform to exercise
    // that branch's own true/false paths for real -- reuses
    // DqlPlatformQueryTestFactory's reflection-forced-platform technique
    // (an in-memory SQLite connection lying about its platform via
    // reflection on Connection::$platform) instead of a hand-faked
    // double, same precedent as every Db/DqlFunction/* test.
    $method = new ReflectionMethod(TelemetryService::class, 'detectDriverLabel');

    $mariaDbEm = DqlPlatformQueryTestFactory::entityManagerForPlatform(new MariaDBPlatform());
    $mariaDbService = new TelemetryService($mariaDbEm, $mariaDbEm->getRepository(ConfigEntry::class));
    expect($method->invoke($mariaDbService))->toBe('mariadb');

    $mysqlEm = DqlPlatformQueryTestFactory::entityManagerForPlatform(new MySQLPlatform());
    $mysqlService = new TelemetryService($mysqlEm, $mysqlEm->getRepository(ConfigEntry::class));
    expect($method->invoke($mysqlService))->toBe('mysql');

    $pgsqlEm = DqlPlatformQueryTestFactory::entityManagerForPlatform(new PostgreSQLPlatform());
    $pgsqlService = new TelemetryService($pgsqlEm, $pgsqlEm->getRepository(ConfigEntry::class));
    expect($method->invoke($pgsqlService))->toBe('pgsql');

    $oracleEm = DqlPlatformQueryTestFactory::entityManagerForPlatform(new OraclePlatform());
    $oracleService = new TelemetryService($oracleEm, $oracleEm->getRepository(ConfigEntry::class));
    expect($method->invoke($oracleService))->toBe('unknown');
});

test('buildPayload() assembles a real, structurally-complete payload', function (): void {
    $conn = DbConnection::build();

    try {
        $payload = telemetryTestService()->buildPayload();

        // This environment's real connection is a MySQL8x-family platform
        // (confirmed live: DbConnection::build()->getDatabasePlatform()
        // is Doctrine\DBAL\Platforms\MySQL84Platform, which extends
        // MySQLPlatform -> AbstractMySQLPlatform, not MariaDBPlatform) --
        // asserting the exact label, not just "not unknown", is what
        // actually exercises detectDriverLabel()'s own MariaDB-vs-MySQL
        // instanceof branches.
        expect($payload->installId)->toMatch('/^[0-9a-f]{32}$/')
            ->and($payload->environment->phpVersion)->toBe(PHP_VERSION)
            ->and($payload->environment->osFamily)->toBe(PHP_OS_FAMILY)
            ->and($payload->database->driver)->toBe('mysql')
            ->and($payload->database->serverVersion)->not->toBe('');
    } finally {
        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->executeStatement();
    }
});

test('buildPayload() gallery/extension stats match real raw COUNT(*) queries', function (): void {
    $conn = DbConnection::build();

    try {
        $payload = telemetryTestService()->buildPayload();

        $realImageCount = $conn->createQueryBuilder()->select('COUNT(*)')->from(Tables::images())->fetchOne();
        $realUserCount = $conn->createQueryBuilder()->select('COUNT(*)')->from(Tables::users())->fetchOne();
        $realPluginCount = $conn->createQueryBuilder()->select('COUNT(*)')->from(Tables::plugins())->fetchOne();
        expect($realImageCount)->toBeInt();
        expect($realUserCount)->toBeInt();
        expect($realPluginCount)->toBeInt();

        expect($payload->gallery->imageCount)->toBe($realImageCount)
            ->and($payload->gallery->userCount)->toBe($realUserCount)
            ->and($payload->extensions->pluginCount)->toBe($realPluginCount);
    } finally {
        $conn->createQueryBuilder()
            ->delete(Tables::config())
            ->where('param = :param')
            ->setParameter('param', 'telemetry_install_id')
            ->executeStatement();
    }
});
