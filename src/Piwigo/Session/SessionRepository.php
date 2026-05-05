<?php

declare(strict_types=1);

namespace Piwigo\Session;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the DB-backed PHP session store. */
final class SessionRepository extends AbstractRepository
{
    public function read(string $compositeId): string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('data')
            ->from($this->table('sessions'))
            ->where('id = :id')
            ->setParameter('id', $compositeId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : '';
    }

    public function write(string $compositeId, string $data): void
    {
        // REPLACE INTO has no query-builder equivalent in DBAL; raw SQL + bindings is safe here.
        $this->conn->executeStatement(
            'REPLACE INTO ' . $this->table('sessions') . ' (id, data, expiration) VALUES (?, ?, NOW())',
            [$compositeId, $data]
        );
    }

    public function destroy(string $compositeId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('sessions'))
            ->where('id = :id')
            ->setParameter('id', $compositeId)
            ->executeStatement();
    }

    public function gc(int $sessionLength): void
    {
        // UNIX_TIMESTAMP() is MySQL-specific; 16.x is MySQL-only so raw SQL is acceptable.
        $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('sessions') .
            ' WHERE UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(expiration) > ?',
            [$sessionLength]
        );
    }

    public function deleteByUserId(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('sessions'))
            ->where('data LIKE :pattern')
            ->setParameter('pattern', '%pwg_uid|i:' . $userId . ';%')
            ->executeStatement();
    }

    /**
     * Delete sessions by their composite id strings.
     * Used by maintenance to purge sessions belonging to deleted users.
     *
     * @param string[] $compositeIds
     */
    public function deleteByIds(array $compositeIds): void
    {
        if ($compositeIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('sessions'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $compositeIds, ArrayParameterType::STRING);
        $qb->executeStatement();
    }
}
