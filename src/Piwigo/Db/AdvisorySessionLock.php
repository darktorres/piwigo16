<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Shared MySQL `GET_LOCK()`/`RELEASE_LOCK()` <->
 * Postgres `pg_try_advisory_lock()`/`pg_advisory_unlock()` translation,
 * extracted from `Piwigo\Core\UniqueExecLock` (its own docblock has the
 * full research trail: reentrancy per session, the `unpack('J', ...)`
 * bigint-key derivation, why a poll loop is needed to emulate
 * `GET_LOCK()`'s blocking-with-a-timeout since `pg_try_advisory_lock()`
 * never blocks at all). `UniqueExecLock` and `Admin\Upload\
 * UploadService::upload()`'s duplicate-detection lock each build their
 * own already-hashed, MySQL-shaped lock-name string (capped at MySQL's
 * own 64-character `GET_LOCK()` limit) and use it verbatim for the MySQL
 * branch -- {@see key()} re-hashes that same string for the Postgres
 * branch's bigint key, so every caller only ever has to build and carry
 * one string.
 */
final class AdvisorySessionLock
{
    public static function acquire(Connection $conn, string $lockName, int $timeoutSeconds = 0): bool
    {
        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return self::acquirePostgres($conn, $lockName, $timeoutSeconds);
        }

        return $conn->fetchOne(<<<SQL
            SELECT GET_LOCK(?, ?)
            SQL
            , [$lockName, $timeoutSeconds]) === 1;
    }

    public static function release(Connection $conn, string $lockName): void
    {
        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $conn->fetchOne(<<<SQL
                SELECT pg_advisory_unlock(?)
                SQL
                , [self::key($lockName)]);

            return;
        }

        $conn->fetchOne(<<<SQL
            SELECT RELEASE_LOCK(?)
            SQL
            , [$lockName]);
    }

    private static function acquirePostgres(Connection $conn, string $lockName, int $timeoutSeconds): bool
    {
        $key = self::key($lockName);
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            $acquired = (bool) $conn->fetchOne(<<<SQL
                SELECT pg_try_advisory_lock(?)
                SQL
                , [$key]);
            if ($acquired || microtime(true) >= $deadline) {
                return $acquired;
            }

            usleep(100_000);
        }
    }

    /**
     * Postgres advisory lock names are a `bigint` key, not an arbitrary
     * string -- first 8 bytes of the raw (non-hex) sha1 digest,
     * reinterpreted as a signed 64-bit int via `unpack('J', ...)`.
     * {@see \Piwigo\Core\UniqueExecLock}'s own docblock has the full
     * verification trail for why this round-trips correctly across the
     * entire bigint range.
     */
    public static function key(string $lockName): int
    {
        $rawHash = sha1($lockName, true);

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr($rawHash, 0, 8));

        return $unpacked[1];
    }
}
