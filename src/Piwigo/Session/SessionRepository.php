<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the DB-backed PHP session store.
 */
final class SessionRepository extends AbstractRepository
{
    public function read(string $compositeId): string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('data')
            ->from(Tables::sessions())
            ->where('id = :id')
            ->setParameter('id', $compositeId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : '';
    }

    public function write(string $compositeId, string $data): void
    {
        // REPLACE INTO has no query-builder equivalent in DBAL; raw SQL +
        // bindings is safe here (no string concatenation of $data).
        //
        // pwg_now() rather than a bare `new \DateTimeImmutable()`, which
        // reads the real system clock -- invisible to pwg_now()'s
        // PIWIGO_TEST_NOW freeze, so every login during a fixture
        // regeneration wrote a fresh, non-reproducible expiration.
        $this->conn->executeStatement(
            'REPLACE INTO ' . Tables::sessions() . ' (id, data, expiration) VALUES (?, ?, ?)',
            [$compositeId, $data, \DateTimeImmutable::createFromInterface(pwg_now())],
            [ParameterType::STRING, ParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );
    }

    public function destroy(string $compositeId): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::sessions())
            ->where('id = :id')
            ->setParameter('id', $compositeId)
            ->executeStatement();
    }

    /**
     * Deletes every session whose expiration is older than $sessionLength
     * seconds, returning the number of deleted rows (matches the current
     * procedural pwg_session_gc()'s real pwg_db_changes()-backed return
     * value -- not discarded the way the reference implementation's
     * equivalent does). The cutoff is computed in PHP and bound as a plain
     * parameter -- cross-provider safe (no MySQL-only UNIX_TIMESTAMP()/
     * PostgreSQL-only interval syntax), unlike a raw
     * "NOW() - expiration > N seconds" comparison would require.
     */
    public function gc(int $sessionLength): int
    {
        $cutoff = new \DateTimeImmutable('-' . $sessionLength . ' seconds');

        $affected = $this->conn->createQueryBuilder()
            ->delete(Tables::sessions())
            ->where('expiration < :cutoff')
            ->setParameter('cutoff', $cutoff, Types::DATETIME_IMMUTABLE)
            ->executeStatement();

        return (int) $affected;
    }

    /**
     * Delete all sessions for a given user.
     */
    public function deleteByUserId(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM ' . Tables::sessions() . ' WHERE data LIKE ?',
            ['%pwg_uid|i:' . $userId . ';%'],
        );
    }
}
